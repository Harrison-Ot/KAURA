<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\CatalogueValues;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-22T09:00:00Z'));
        $this->owner = User::factory()->create(['role' => 'owner', 'name' => 'Original owner']);
        ShopSettings::query()->findOrFail(1)->update(['shop_name' => 'Original hardware', 'address' => 'Original address', 'contact' => 'Original contact']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_cash_checkout_posts_sale_stock_cost_and_net_collection_together(): void
    {
        $product = $this->stockedProduct();
        $employee = User::factory()->create();
        $this->actingAs($employee);
        $sale = $this->checkout($this->data($product, '2', '50.00'));
        $this->assertSame('KS-20261122-000001', $sale->reference);
        $this->assertSame(2000, $sale->total_minor);
        $this->assertSame(1402, $sale->cost_total_minor);
        $this->assertSame(3000, $sale->change_minor);
        $this->assertSame($employee->id, $sale->sold_by);
        $this->assertSame('3.000', $product->refresh()->quantity_on_hand);
        $this->assertSame(2103, $product->inventory_value_minor);
        $this->assertDatabaseHas('payments', ['sale_id' => $sale->id, 'handled_by' => $employee->id, 'method' => 'cash', 'status' => 'verified', 'amount_minor' => 2000]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'sku' => $product->sku, 'unit_price_minor' => 1000, 'unit_cost_minor' => 701, 'cost_total_minor' => 1402]);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'sale', 'quantity_delta' => -2, 'balance_after' => 3, 'value_delta_minor' => -1402]);
        $this->assertDatabaseCount('receivables', 0);
        $this->assertDatabaseHas('audit_events', ['event' => 'sale.posted', 'resource_id' => $sale->id]);
        $this->get('/sales/'.$sale->id)->assertInertia(fn (Assert $page) => $page->component('sales/show')->where('sale.total', '20.00')->where('sale.payments.0.amount', '20.00')->missing('sale.cost_total')->missing('sale.items.0.unit_cost')->missing('sale.items.0.cost_total'));
        $this->actingAs($this->owner)->get('/owner/inventory/ledger')->assertInertia(fn (Assert $page) => $page->has('movements.data', 2)->where('movements.data.0.sale_id', $sale->id));
    }

    public function test_checkout_retries_return_original_even_after_price_or_stock_change(): void
    {
        $product = $this->stockedProduct('1');
        $this->actingAs(User::factory()->create());
        $data = $this->data($product, '1', '20');
        $sale = $this->checkout($data);
        $product->update(['selling_price_minor' => 1500, 'is_active' => false]);
        $this->post('/sales', $data)->assertSessionHasNoErrors()->assertRedirect('/sales/'.$sale->id);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('stock_movements')->where('kind', 'sale')->count());
        $this->assertSame('0.000', $product->refresh()->quantity_on_hand);
        $this->post('/sales', [...$data, 'cash_tendered' => '30'])->assertSessionHasErrors('submission_key');
    }

    public function test_two_buyers_cannot_sequentially_buy_the_same_last_unit(): void
    {
        $product = $this->stockedProduct('1');
        $this->actingAs(User::factory()->create());
        $this->checkout($this->data($product, '1', '10'));
        $this->actingAs(User::factory()->create())->post('/sales', $this->data($product, '1', '10'))->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('0.000', $product->refresh()->quantity_on_hand);
    }

    public function test_price_change_is_rejected_and_refreshed_quote_can_post(): void
    {
        $product = $this->stockedProduct();
        $this->actingAs(User::factory()->create());
        $data = $this->data($product, '1', '20');
        $product->update(['selling_price_minor' => 1200]);
        $this->post('/sales', $data)->assertSessionHasErrors('items.0.quoted_price');
        $this->assertDatabaseCount('sales', 0);
        $data['items'][0]['quoted_price'] = '12.00';
        $this->checkout($data);
        $this->assertDatabaseHas('sales', ['total_minor' => 1200]);
    }

    public function test_inactive_product_short_cash_and_forged_discount_are_rejected(): void
    {
        $product = $this->stockedProduct();
        $this->actingAs(User::factory()->create());
        $this->post('/sales', $this->data($product, '1', '9.99'))->assertSessionHasErrors('cash_tendered');
        $this->post('/sales', [...$this->data($product), 'discount' => '1', 'credit_amount' => '5', 'sold_by' => $this->owner->id])->assertSessionHasErrors(['discount', 'credit_amount', 'sold_by']);
        $this->post('/sales', [...$this->data($product), 'payment_method' => 'stk_push'])->assertSessionHasErrors('payment_method');
        $product->update(['is_active' => false]);
        $this->post('/sales', $this->data($product))->assertSessionHasErrors('items.0.product_id');
        $this->assertSame('5.000', $product->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invalid_cart_lines_quantities_and_tender_are_rejected(): void
    {
        $product = $this->stockedProduct();
        $this->actingAs(User::factory()->create());
        $data = $this->data($product);
        $this->post('/sales', [...$data, 'items' => [$data['items'][0], $data['items'][0]]])->assertSessionHasErrors('items.0.product_id');
        $this->post('/sales', $this->data($product, '0', '10'))->assertSessionHasErrors('items.0.quantity');
        $this->post('/sales', $this->data($product, '0.5', '10'))->assertSessionHasErrors('items.0.quantity');
        $this->post('/sales', $this->data($product, '6', '100'))->assertSessionHasErrors('items.0.quantity');
        $this->post('/sales', $this->data($product, '1', '1e5'))->assertSessionHasErrors('cash_tendered');
        $this->post('/sales', [...$data, 'items' => []])->assertSessionHasErrors('items');
        $this->post('/sales', [...$data, 'items' => ['bad-key' => $data['items'][0]]])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cost_rounding_assigns_remaining_value_on_final_depletion(): void
    {
        $product = $this->stockedProduct('0.3', '0.05', 3, '1.00');
        $this->actingAs(User::factory()->create());
        $this->assertSame(2, $product->refresh()->inventory_value_minor);
        $first = $this->checkout($this->data($product, '0.1', '0.10'));
        $second = $this->checkout($this->data($product, '0.1', '0.10'));
        $last = $this->checkout($this->data($product, '0.1', '0.10'));
        $this->assertSame(2, $first->cost_total_minor + $second->cost_total_minor + $last->cost_total_minor);
        $this->assertSame(0, $product->refresh()->inventory_value_minor);
        $this->assertNull($product->average_cost_minor);
        $this->assertSame('0.000', $product->quantity_on_hand);
        $this->assertSame(0, (int) DB::table('stock_movements')->sum('value_delta_minor'));
    }

    public function test_unknown_cost_remains_on_sale_but_empty_stock_value_resets_to_zero(): void
    {
        $product = $this->stockedProduct('1', null);
        $this->actingAs($this->owner);
        $sale = $this->checkout($this->data($product));
        $this->assertNull($sale->cost_total_minor);
        $this->assertSame(0, $product->refresh()->inventory_value_minor);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'cost_total_minor' => null, 'unit_cost_minor' => null]);
        $this->get('/sales/'.$sale->id)->assertInertia(fn (Assert $page) => $page->where('sale.cost_total', null));
        $this->get('/sales/'.$sale->id.'/receipt')->assertOk()->assertDontSee('Inventory cost');
    }

    public function test_receipt_and_sale_snapshots_survive_later_edits_and_deactivation(): void
    {
        $product = $this->stockedProduct();
        $employee = User::factory()->create(['name' => 'Original employee']);
        $customer = new Customer(['name' => 'Original customer', 'name_key' => 'original customer', 'contact' => 'Original customer contact', 'contact_key' => 'originalcustomer']);
        $customer->setAttribute('created_by', $this->owner->id);
        $customer->save();
        $this->actingAs($employee);
        $sale = $this->checkout([...$this->data($product, '2', '50.00'), 'customer_id' => $customer->id]);
        $product->update(['name' => 'Changed product', 'selling_price_minor' => 9900, 'purchase_cost_minor' => 1]);
        $customer->update(['name' => 'Changed customer', 'contact' => 'Changed contact']);
        $employee->name = 'Changed employee';
        $employee->is_active = false;
        $employee->save();
        ShopSettings::query()->findOrFail(1)->update(['shop_name' => 'Changed shop', 'address' => 'Changed address']);
        $this->actingAs($this->owner)->get('/sales/'.$sale->id.'/receipt')->assertOk()
            ->assertSee('Original hardware')->assertSee('Original address')->assertSee('Original employee')->assertSee('Original customer')->assertSee('Original bolt')
            ->assertSee('20.00')->assertSee('50.00')->assertSee('30.00')->assertSee('Print receipt')
            ->assertDontSee('Changed product')->assertDontSee('Changed shop')->assertDontSee('Changed employee')->assertDontSee('7.01')->assertDontSee('99.99');
        $this->assertSame($employee->id, $sale->refresh()->sold_by);
        $this->assertSame(1000, $sale->items->firstOrFail()->unit_price_minor);
        $this->actingAs($employee->refresh())->get('/sales/'.$sale->id.'/receipt')->assertRedirect('/login');
    }

    public function test_employee_sale_and_payment_queries_are_scoped_and_direct_receipts_are_denied(): void
    {
        $product = $this->stockedProduct();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first);
        $firstSale = $this->checkout($this->data($product));
        $this->actingAs($second);
        $secondSale = $this->checkout($this->data($product));
        $this->get('/sales')->assertInertia(fn (Assert $page) => $page->component('sales/index')->has('sales.data', 1)->where('sales.data.0.id', $secondSale->id)->has('employees', 0));
        $this->get('/payments')->assertInertia(fn (Assert $page) => $page->component('payments/index')->has('payments.data', 1)->where('payments.data.0.sale_id', $secondSale->id));
        $this->get('/sales/'.$firstSale->id)->assertForbidden();
        $this->get('/sales/'.$firstSale->id.'/receipt')->assertForbidden();
        $this->get('/sales?employee_id='.$first->id)->assertSessionHasErrors('employee_id');
        $this->get('/payments?employee_id='.$first->id)->assertSessionHasErrors('employee_id');
        $this->assertSame(2, DB::table('audit_events')->where('event', 'access.denied')->count());
        $this->actingAs($this->owner)->get('/sales')->assertInertia(fn (Assert $page) => $page->has('sales.data', 2));
        $this->get('/sales?employee_id='.$first->id)->assertInertia(fn (Assert $page) => $page->has('sales.data', 1)->where('sales.data.0.id', $firstSale->id));
        $this->get('/payments?employee_id='.$second->id)->assertInertia(fn (Assert $page) => $page->has('payments.data', 1));
        $this->get('/sales/'.$firstSale->id)->assertInertia(fn (Assert $page) => $page->where('sale.cost_total', '7.01'));
    }

    public function test_another_employee_cannot_reuse_a_posted_checkout_key(): void
    {
        $product = $this->stockedProduct();
        $data = $this->data($product);
        $this->actingAs(User::factory()->create());
        $this->checkout($data);
        $this->actingAs(User::factory()->create())->post('/sales', $data)->assertForbidden();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('audit_events', ['event' => 'access.denied', 'actor_id' => auth()->id()]);
    }

    public function test_opening_signoff_and_authentication_are_required(): void
    {
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $product = Product::create(['sku' => 'TEST-0', 'name' => 'Test product', 'name_key' => 'test product', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $this->get('/sales')->assertRedirect('/login');
        $this->get('/sales/create')->assertRedirect('/login');
        $this->get('/payments')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/sales/create')->assertInertia(fn (Assert $page) => $page->component('sales/create')->where('openingReady', false)->missing('products.0.purchase_cost_minor')->missing('products.0.average_cost_minor'));
        $this->post('/sales', $this->data($product))->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_nairobi_business_day_and_optional_date_filters_are_correct(): void
    {
        $product = $this->stockedProduct();
        $this->travelTo(CarbonImmutable::parse('2026-11-22T21:30:00Z'));
        $this->actingAs(User::factory()->create());
        $sale = $this->checkout($this->data($product));
        $this->assertSame('2026-11-23', $sale->getAttribute('business_date'));
        $this->get('/sales?from=2026-11-23&to=2026-11-23')->assertInertia(fn (Assert $page) => $page->has('sales.data', 1));
        $this->get('/sales?to=2026-11-22')->assertInertia(fn (Assert $page) => $page->has('sales.data', 0));
        $this->get('/payments?from=2026-11-23')->assertInertia(fn (Assert $page) => $page->has('payments.data', 1));
        $this->get('/payments?to=2026-11-22')->assertInertia(fn (Assert $page) => $page->has('payments.data', 0));
        $this->get('/sales?from=2026-11-24&to=2026-11-23')->assertSessionHasErrors('to');
        $this->get('/sales/'.$sale->id.'/receipt')->assertSee('23 Nov 2026 00:30');
    }

    public function test_audit_failure_rolls_back_sale_payment_stock_and_value(): void
    {
        $product = $this->stockedProduct();
        $this->actingAs(User::factory()->create());
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/sales', $this->data($product));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('5.000', $product->refresh()->quantity_on_hand);
        $this->assertSame(3505, $product->inventory_value_minor);
        $this->assertSame(0, DB::table('stock_movements')->where('kind', 'sale')->count());
    }

    public function test_sales_and_collections_are_paginated_and_immutable(): void
    {
        $product = $this->stockedProduct('25');
        $this->actingAs(User::factory()->create());
        for ($index = 0; $index < 21; $index++) {
            $this->checkout($this->data($product));
        }
        $this->get('/sales')->assertInertia(fn (Assert $page) => $page->has('sales.data', 20)->where('sales.total', 21));
        $this->get('/sales?page=2')->assertInertia(fn (Assert $page) => $page->has('sales.data', 1));
        $this->get('/payments')->assertInertia(fn (Assert $page) => $page->has('payments.data', 20)->where('payments.total', 21));
        $sale = Sale::query()->firstOrFail();
        $this->patch('/sales/'.$sale->id, ['total' => '1'])->assertMethodNotAllowed();
        $this->delete('/sales/'.$sale->id)->assertMethodNotAllowed();
        $this->assertSame(21, Payment::query()->count());
    }

    public function test_multiple_line_checkout_fails_as_a_whole_when_one_line_has_no_stock(): void
    {
        $product = $this->stockedProduct();
        $empty = Product::create(['sku' => 'EMPTY-2', 'name' => 'Empty product', 'name_key' => 'empty product', 'category_id' => $product->category_id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $this->actingAs(User::factory()->create());
        $data = $this->data($product, '1', '20');
        $data['items'][] = ['product_id' => $empty->id, 'quantity' => '1', 'quoted_price' => '10.00'];
        $this->post('/sales', $data)->assertSessionHasErrors('items.1.quantity');
        $this->assertSame('5.000', $product->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_customer_supplied_text_is_escaped_on_printable_receipt(): void
    {
        $product = $this->stockedProduct();
        $product->update(['name' => '<script>alert(123)</script>']);
        $this->actingAs(User::factory()->create());
        $sale = $this->checkout($this->data($product));
        $this->get('/sales/'.$sale->id.'/receipt')->assertOk()->assertSee('&lt;script&gt;alert(123)&lt;/script&gt;', false)->assertDontSee('<script>alert(123)</script>', false);
    }

    public function test_stock_movements_require_a_real_batch_or_sale_source(): void
    {
        $product = $this->stockedProduct();
        $this->expectException(QueryException::class);
        DB::table('stock_movements')->insert(['product_id' => $product->id, 'batch_item_id' => null, 'sale_item_id' => null, 'kind' => 'sale', 'quantity_delta' => -1, 'balance_after' => 4, 'value_delta_minor' => -701, 'effective_on' => '2026-11-22', 'actor_id' => $this->owner->id, 'created_at' => now()]);
    }

    public function test_migration_rollback_refuses_to_destroy_posted_sales(): void
    {
        $product = $this->stockedProduct();
        $this->actingAs($this->owner);
        $sale = $this->checkout($this->data($product));
        $migration = require database_path('migrations/2026_11_22_000004_create_cash_sales.php');
        try {
            $migration->down();
            $this->fail('Expected rollback protection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Sales are posted', $exception->getMessage());
        }
        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('stock_movements')->where('kind', 'sale')->count());
    }

    public function test_sale_migration_roundtrip_preserves_existing_inventory_without_sales(): void
    {
        $product = $this->stockedProduct();
        $movement = DB::table('stock_movements')->first();
        $payoutMigration = require database_path('migrations/2026_11_23_000011_create_customer_credit_payouts.php');
        $payoutMigration->down();
        $resolutionMigration = require database_path('migrations/2026_11_23_000010_create_void_resolutions.php');
        $resolutionMigration->down();
        $voidMigration = require database_path('migrations/2026_11_22_000009_create_sale_voids.php');
        $voidMigration->down();
        $countMigration = require database_path('migrations/2026_11_22_000007_create_stock_counts.php');
        $countMigration->down();
        $approvalMigration = require database_path('migrations/2026_11_22_000006_create_approvals_and_instalments.php');
        $approvalMigration->down();
        $verificationMigration = require database_path('migrations/2026_11_22_000005_add_manual_mpesa_verification.php');
        $verificationMigration->down();
        $migration = require database_path('migrations/2026_11_22_000004_create_cash_sales.php');
        $migration->down();
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id, 'batch_item_id' => $movement->batch_item_id, 'quantity_delta' => 5]);
        $this->assertSame(3505, $product->refresh()->inventory_value_minor);
        $migration->up();
        $verificationMigration->up();
        $approvalMigration->up();
        $countMigration->up();
        $voidMigration->up();
        $resolutionMigration->up();
        $payoutMigration->up();
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id, 'batch_item_id' => $movement->batch_item_id, 'sale_item_id' => null]);
        $this->actingAs($this->owner);
        $this->checkout($this->data($product));
        $this->assertSame('4.000', $product->refresh()->quantity_on_hand);
        $this->assertSame(2804, $product->inventory_value_minor);
    }

    public function test_large_checkout_totals_are_rejected_with_a_visible_cart_error(): void
    {
        $product = $this->stockedProduct('999999999', '0.00', 0, '999999999.99');
        $this->actingAs($this->owner)->post('/sales', $this->data($product, '999999999', '10'))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('999999999.000', $product->refresh()->quantity_on_hand);
    }

    private function stockedProduct(string $quantity = '5', ?string $cost = '7.01', int $precision = 0, string $price = '10.00'): Product
    {
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $product = Product::create(['sku' => 'BOLT-1', 'name' => 'Original bolt', 'name_key' => 'original bolt', 'category_id' => $category->id, 'unit_label' => $precision === 0 ? 'piece' : 'kg', 'quantity_precision' => $precision, 'selling_price_minor' => CatalogueValues::minorUnits($price), 'purchase_cost_minor' => 9999, 'reorder_threshold' => '0']);
        $service = app(InventoryService::class);
        $batch = $service->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Test opening count', 'rows' => [['sku' => $product->sku, 'unit' => $product->unit_label, 'quantity' => $quantity, 'unit_cost' => $cost]]]);
        $service->post($this->owner, $batch, 'Test owner review');
        $service->completeOpening($this->owner, '0.00', 'Test sign-off', '2026-11-21');

        return $product->refresh();
    }

    /** @return array<string, mixed> */
    private function data(Product $product, string $quantity = '1', string $tendered = '10.00'): array
    {
        return ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => $tendered, 'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'quoted_price' => CatalogueValues::money($product->selling_price_minor)]]];
    }

    /** @param array<string, mixed> $data */
    private function checkout(array $data): Sale
    {
        $response = $this->post('/sales', $data)->assertSessionHasNoErrors();
        $sale = Sale::query()->where('submission_key', $data['submission_key'])->firstOrFail();
        $response->assertRedirect('/sales/'.$sale->id);

        return $sale;
    }
}
