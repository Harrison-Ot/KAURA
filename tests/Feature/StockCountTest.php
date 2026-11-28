<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ShopSettings;
use App\Models\StockCount;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(22, 30));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->employee = User::factory()->create();
        ShopSettings::findOrFail(1)->update(['shop_name' => 'Test shop']);
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $this->product = Product::create(['sku' => 'COUNT-1', 'name' => 'Counted product', 'name_key' => 'counted product', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $service = app(InventoryService::class);
        $batch = $service->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Test count', 'rows' => [['sku' => $this->product->sku, 'unit' => 'piece', 'quantity' => '3', 'unit_cost' => '3.01']]]);
        $service->post($this->owner, $batch, 'Reviewed');
        $service->completeOpening($this->owner, '0.00', 'Sign off', '2026-11-21');
        $this->product->refresh();
        $this->actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_employee_count_is_inert_and_retries_preserve_one_snapshot(): void
    {
        $data = $this->data('2');
        $this->post('/stock-counts', $data)->assertSessionHasNoErrors();
        $this->post('/stock-counts', $data)->assertSessionHasNoErrors();
        $count = StockCount::sole();
        $this->assertSame('pending', $count->status);
        $this->assertSame('3.000', $this->product->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('stock_counts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_count_items', ['system_quantity' => 3, 'counted_quantity' => 2, 'quantity_delta' => -1, 'record_version' => $this->product->version]);
        $this->get('/stock-counts/'.$count->id)->assertInertia(fn (Assert $page) => $page->where('count.counted_by_name', $this->employee->name)->where('items.0.quantity_delta', '-1.000')->missing('items.0.value_before')->missing('items.0.value_after'));
        $this->post('/stock-counts', [...$data, 'reason' => 'Changed payload'])->assertSessionHasErrors('count');
    }

    public function test_owner_approval_posts_loss_once_at_nairobi_date_and_reconciles_stock(): void
    {
        $count = $this->submit('2');
        $this->approve($count);
        $this->approve($count);
        $this->assertSame('2.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(602, $this->product->inventory_value_minor);
        $this->assertSame(301, $this->product->average_cost_minor);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'stock_adjustment', 'quantity_delta' => -1, 'value_delta_minor' => -301, 'balance_after' => 2, 'actor_id' => $this->owner->id, 'effective_on' => '2026-11-23']);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'stock_count.approved')->count());
        $this->assertEquals(2, DB::table('stock_movements')->sum('quantity_delta'));
        $this->get('/owner/inventory/ledger')->assertInertia(fn (Assert $page) => $page->where('movements.data.0.count_id', $count->id)->where('movements.data.0.label', 'Counted product'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_outflows', 0);
    }

    public function test_gain_uses_carried_cost_and_zero_discrepancy_keeps_explicit_record(): void
    {
        $count = $this->submit('5');
        $this->approve($count);
        $this->assertSame(1505, $this->product->refresh()->inventory_value_minor);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'stock_adjustment', 'quantity_delta' => 2, 'value_delta_minor' => 602]);
        $count = $this->submit('5');
        $this->approve($count);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'stock_adjustment', 'quantity_delta' => 0, 'value_delta_minor' => 0]);
        $this->assertSame('5.000', $this->product->refresh()->quantity_on_hand);
    }

    public function test_unknown_costs_stay_unknown_until_empty_and_found_stock_gets_no_invented_cost(): void
    {
        $this->product->forceFill(['inventory_value_minor' => null, 'average_cost_minor' => null])->save();
        $count = $this->submit('2');
        $this->approve($count);
        $this->assertNull($this->product->refresh()->inventory_value_minor);
        $count = $this->submit('0');
        $this->approve($count);
        $this->assertSame(0, $this->product->refresh()->inventory_value_minor);
        $count = $this->submit('1');
        $this->approve($count);
        $this->assertNull($this->product->refresh()->inventory_value_minor);
        $this->assertNull($this->product->average_cost_minor);
    }

    public function test_counts_are_private_and_staff_cannot_approve_or_forge_attribution(): void
    {
        $count = $this->submit('2');
        $this->post('/owner/stock-counts/'.$count->id.'/decision', $this->decision())->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/stock-counts/'.$count->id)->assertForbidden();
        $this->get('/stock-counts')->assertInertia(fn (Assert $page) => $page->has('counts.data', 0));
        $this->actingAs($this->employee)->post('/stock-counts', [...$this->data('2'), 'counted_by' => $this->owner->id])->assertSessionHasErrors('counted_by');
        $this->actingAs($this->owner)->get('/stock-counts/'.$count->id)->assertInertia(fn (Assert $page) => $page->where('items.0.value_before', '9.03'));
        $this->get('/stock-counts')->assertInertia(fn (Assert $page) => $page->has('counts.data', 1));
    }

    public function test_real_checkout_makes_count_stale_and_approval_cannot_overwrite_sale(): void
    {
        $count = $this->submit('2');
        $this->post('/sales', ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => '10.00', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.00']]])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/owner/stock-counts/'.$count->id.'/decision', $this->decision())->assertSessionHasErrors('decision');
        $this->assertSame('pending', $count->refresh()->status);
        $this->assertSame('2.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(0, DB::table('stock_movements')->where('kind', 'stock_adjustment')->count());
        $this->get('/stock-counts/'.$count->id)->assertInertia(fn (Assert $page) => $page->where('items.0.stale', true));
    }

    public function test_stale_form_duplicate_listing_and_wrong_precision_cannot_submit(): void
    {
        $data = $this->data('2');
        $this->product->version++;
        $this->product->save();
        $this->post('/stock-counts', $data)->assertSessionHasErrors('items.0.counted_quantity');
        $this->post('/stock-counts', $this->data('1.5'))->assertSessionHasErrors('items.0.counted_quantity');
        $data = $this->data('2');
        $data['items'][] = $data['items'][0];
        $this->post('/stock-counts', $data)->assertSessionHasErrors('items.0.product_id');
        $this->assertDatabaseCount('stock_counts', 0);
    }

    public function test_rejected_count_is_final_and_has_no_movement(): void
    {
        $count = $this->submit('2');
        $this->actingAs($this->owner)->post('/owner/stock-counts/'.$count->id.'/decision', [...$this->decision(), 'decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->post('/owner/stock-counts/'.$count->id.'/decision', $this->decision())->assertSessionHasErrors('decision');
        $this->assertSame('rejected', $count->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('3.000', $this->product->refresh()->quantity_on_hand);
    }

    public function test_opening_must_be_signed_off_and_costs_are_not_sent_to_staff(): void
    {
        $this->get('/stock-counts/create')->assertInertia(fn (Assert $page) => $page->where('openingReady', true)->missing('products.0.inventory_value_minor')->missing('products.0.purchase_cost_minor'));
        ShopSettings::findOrFail(1)->update(['opening_completed_at' => null]);
        $this->post('/stock-counts', $this->data('2'))->assertSessionHasErrors('count');
        $this->assertDatabaseCount('stock_counts', 0);
    }

    public function test_audit_failure_rolls_back_adjustment_values_status_and_movements(): void
    {
        $count = $this->submit('2');
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->owner)->post('/owner/stock-counts/'.$count->id.'/decision', $this->decision());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $count->refresh()->status);
        $this->assertSame('3.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(903, $this->product->inventory_value_minor);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_count_items', ['stock_count_id' => $count->id, 'value_after_minor' => null]);
    }

    public function test_multiline_stale_record_rolls_back_every_line(): void
    {
        $other = $this->product->replicate();
        $other->sku = 'COUNT-2';
        $other->name_key = 'second';
        $other->save();
        $data = $this->data('2');
        $data['items'][] = ['product_id' => $other->id, 'expected_version' => $other->version, 'counted_quantity' => '1'];
        $this->post('/stock-counts', $data)->assertSessionHasNoErrors();
        $other->version++;
        $other->save();
        $count = StockCount::sole();
        $this->actingAs($this->owner)->post('/owner/stock-counts/'.$count->id.'/decision', $this->decision())->assertSessionHasErrors('decision');
        $this->assertSame('3.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(903, $this->product->inventory_value_minor);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_migration_roundtrip_preserves_existing_history_and_refuses_after_count(): void
    {
        $payoutMigration = require database_path('migrations/2026_11_23_000011_create_customer_credit_payouts.php');
        $payoutMigration->down();
        $resolutionMigration = require database_path('migrations/2026_11_23_000010_create_void_resolutions.php');
        $resolutionMigration->down();
        $voidMigration = require database_path('migrations/2026_11_22_000009_create_sale_voids.php');
        $voidMigration->down();
        $migration = require database_path('migrations/2026_11_22_000007_create_stock_counts.php');
        $migration->down();
        $migration->up();
        $voidMigration->up();
        $resolutionMigration->up();
        $payoutMigration->up();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('3.000', $this->product->refresh()->quantity_on_hand);
        $this->submit('2');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_fractional_costing_uses_carried_value_with_exact_rounding(): void
    {
        $this->product->forceFill(['quantity_precision' => 3, 'inventory_value_minor' => 1000])->save();
        $count = $this->submit('1.001');
        $this->approve($count);
        $this->assertSame('1.001', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(334, $this->product->inventory_value_minor);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'stock_adjustment', 'quantity_delta' => -1.999, 'value_delta_minor' => -666]);
    }

    public function test_extreme_gain_cannot_overflow_or_partially_post(): void
    {
        $this->product->forceFill(['quantity_precision' => 3, 'quantity_on_hand' => '0.001', 'inventory_value_minor' => 9000000000000000])->save();
        $count = $this->submit('999999999.999');
        $this->actingAs($this->owner)->post('/owner/stock-counts/'.$count->id.'/decision', $this->decision())->assertSessionHasErrors('decision');
        $this->assertSame('0.001', $this->product->refresh()->quantity_on_hand);
        $this->assertSame('pending', $count->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_movement_requires_exactly_one_source(): void
    {
        $count = $this->submit('2');
        $this->approve($count);
        $movement = DB::table('stock_movements')->where('kind', 'stock_adjustment')->sole();
        $opening = DB::table('stock_movements')->where('kind', 'opening_stock')->sole();
        $this->expectException(QueryException::class);
        DB::table('stock_movements')->where('id', $movement->id)->update(['batch_item_id' => $opening->batch_item_id]);
    }

    /** @return array<string, mixed> */
    private function data(string $quantity): array
    {
        $this->product->refresh();

        return ['submission_key' => (string) Str::uuid(), 'reason' => 'Physical shelf count', 'items' => [['product_id' => $this->product->id, 'expected_version' => $this->product->version, 'counted_quantity' => $quantity]]];
    }

    private function submit(string $quantity): StockCount
    {
        $this->actingAs($this->employee)->post('/stock-counts', $this->data($quantity))->assertSessionHasNoErrors();

        return StockCount::latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function decision(): array
    {
        return ['decision' => 'approved', 'reason' => 'Owner checked physical discrepancy', 'confirm' => true];
    }

    private function approve(StockCount $count): void
    {
        $this->actingAs($this->owner)->post('/owner/stock-counts/'.$count->id.'/decision', $this->decision())->assertSessionHasNoErrors();
    }
}
