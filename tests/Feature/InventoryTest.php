<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ShopSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-22T09:00:00Z'));
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        ShopSettings::query()->findOrFail(1)->update(['shop_name' => 'Test hardware']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_draft_is_inert_and_retry_returns_same_review(): void
    {
        $product = $this->product();
        $data = $this->stockData($product, '3', '10.01');
        $batch = $this->draft($data);
        $this->post('/owner/inventory', $data)->assertRedirect('/owner/inventory/'.$batch->id);
        $this->assertDatabaseCount('inventory_batches', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->assertSame('0.000', $product->refresh()->quantity_on_hand);
        $this->get('/owner/inventory/'.$batch->id)->assertInertia(fn (Assert $page) => $page->component('inventory/show')->where('batch.status', 'draft')->where('batch.total', '30.03')->has('items', 1));
        $this->post('/owner/inventory', [...$data, 'source_note' => 'Different source'])->assertSessionHasErrors('submission_key');
        $this->assertDatabaseCount('inventory_batches', 1);
    }

    public function test_opening_stock_posts_once_and_reconciles_to_movements(): void
    {
        $product = $this->product();
        $batch = $this->draft($this->stockData($product, '3', '10.01'));
        $this->postBatch($batch);
        $this->postBatch($batch);
        $this->assertSame('3.000', $product->refresh()->quantity_on_hand);
        $this->assertSame(3003, $product->inventory_value_minor);
        $this->assertSame(1001, $product->average_cost_minor);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->assertSame(3, (int) DB::table('stock_movements')->where('product_id', $product->id)->sum('quantity_delta'));
        $this->assertDatabaseHas('audit_events', ['event' => 'inventory.posted', 'resource_id' => $batch->id]);
        $this->post('/owner/inventory', $this->stockData($product, '5', '10'))->assertSessionHasErrors('rows.0.sku');
    }

    public function test_stale_count_is_rejected_and_batch_stays_draft(): void
    {
        $product = $this->product();
        $batch = $this->draft($this->stockData($product, '3', '10'));
        $product->version++;
        $product->save();
        $this->post('/owner/inventory/'.$batch->id.'/post', ['confirm' => true, 'reason' => 'Checked'])->assertSessionHasErrors('batch');
        $this->assertSame('draft', $batch->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('0.000', $product->refresh()->quantity_on_hand);
    }

    public function test_missing_cost_stays_unknown_after_later_delivery(): void
    {
        $product = $this->product();
        $opening = $this->draft($this->stockData($product, '3', null));
        $this->get('/owner/inventory/'.$opening->id)->assertInertia(fn (Assert $page) => $page->where('batch.total', null));
        $this->postBatch($opening);
        $this->assertNull($product->refresh()->inventory_value_minor);
        $this->complete();
        $receipt = $this->draft($this->receiptData($product, '2', '12.02', '24.04'));
        $this->postBatch($receipt);
        $this->assertSame('5.000', $product->refresh()->quantity_on_hand);
        $this->assertNull($product->inventory_value_minor);
        $this->assertNull($product->average_cost_minor);
        $this->get('/owner/inventory')->assertInertia(fn (Assert $page) => $page->where('summary.unknown_cost_listings', 1));
    }

    public function test_delivery_posts_stock_and_one_outflow_with_weighted_average(): void
    {
        $product = $this->product();
        $opening = $this->draft($this->stockData($product, '3', '10.01'));
        $this->postBatch($opening);
        $this->complete();
        $receipt = $this->draft($this->receiptData($product, '2', '12.02', '24.04'));
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->postBatch($receipt);
        $this->postBatch($receipt);
        $this->assertSame('5.000', $product->refresh()->quantity_on_hand);
        $this->assertSame(5407, $product->inventory_value_minor);
        $this->assertSame(1081, $product->average_cost_minor);
        $this->assertDatabaseHas('cash_outflows', ['batch_id' => $receipt->id, 'kind' => 'stock_purchase', 'amount_minor' => 2404, 'payment_method' => 'cash']);
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->get('/owner/inventory/ledger?product_id='.$product->id)->assertInertia(fn (Assert $page) => $page->component('inventory/ledger')->has('movements.data', 2));
        $this->get('/products')->assertInertia(fn (Assert $page) => $page->where('products.data.0.average_cost', '10.81')->where('products.data.0.inventory_value', '54.07'));
    }

    public function test_fractional_stock_and_line_rounding_are_exact(): void
    {
        $product = $this->product('SAND-KG', 'kg', 3);
        $batch = $this->draft($this->stockData($product, '0.125', '12.34'));
        $this->postBatch($batch);
        $this->complete();
        $receipt = $this->draft($this->receiptData($product, '0.375', '10.22', '3.83'));
        $this->postBatch($receipt);
        $this->assertSame('0.500', $product->refresh()->quantity_on_hand);
        $this->assertSame(537, $product->inventory_value_minor);
        $this->assertSame(1074, $product->average_cost_minor);
    }

    public function test_opening_debt_is_not_a_collection_or_sale_and_posts_once(): void
    {
        $customer = $this->customer();
        $batch = $this->draft($this->debtData($customer, '2000.15'));
        $this->assertDatabaseCount('receivables', 0);
        $this->postBatch($batch);
        $this->postBatch($batch);
        $this->assertDatabaseHas('receivables', ['customer_id' => $customer->id, 'opening_customer_id' => $customer->id, 'origin' => 'opening_balance', 'original_amount_minor' => 200015, 'remaining_amount_minor' => 200015, 'due_on' => '2026-11-25']);
        $this->assertDatabaseCount('receivables', 1);
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->get('/owner/customers/'.$customer->id.'/statement')->assertInertia(fn (Assert $page) => $page->component('customers/statement')->where('receivables.0.remaining_amount', '2000.15'));
        $this->post('/owner/inventory', $this->debtData($customer, '2000.15'))->assertSessionHasErrors('rows.0.customer_id');
        $this->assertSame(0, $customer->refresh()->credit_limit_minor);
    }

    public function test_duplicate_openings_in_two_drafts_cannot_post_twice(): void
    {
        $product = $this->product();
        $first = $this->draft($this->stockData($product, '3', '10'));
        $second = $this->draft($this->stockData($product, '4', '10'));
        $this->postBatch($first);
        $this->post('/owner/inventory/'.$second->id.'/post', ['confirm' => true, 'reason' => 'Retry'])->assertSessionHasErrors('batch');
        $this->assertSame('3.000', $product->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_csv_import_previews_rows_and_rejects_bad_headers_units_and_duplicates(): void
    {
        $product = $this->product();
        $data = $this->stockData($product, '3', '10');
        unset($data['rows']);
        $this->post('/owner/inventory', [...$data, 'csv' => UploadedFile::fake()->createWithContent('counts.csv', "sku,unit,quantity,unit_cost\nTEST-BAG,bag,3,\n")])->assertRedirect();
        $this->assertDatabaseCount('inventory_batches', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->post('/owner/inventory', [...$data, 'submission_key' => (string) Str::uuid(), 'csv' => UploadedFile::fake()->createWithContent('counts.csv', "sku,quantity\nTEST-BAG,3\n")])->assertSessionHasErrors('csv');
        $this->post('/owner/inventory', $this->stockData($product, '2', '10', ['rows' => [
            ['sku' => 'TEST-BAG', 'unit' => 'kg', 'quantity' => '2', 'unit_cost' => '10'],
        ]]))->assertSessionHasErrors('rows.0.unit');
        $row = ['sku' => $product->sku, 'unit' => 'bag', 'quantity' => '1', 'unit_cost' => '10'];
        $this->post('/owner/inventory', $this->stockData($product, '1', '10', ['rows' => [$row, $row]]))->assertSessionHasErrors('rows.0.sku');
        $this->get('/owner/inventory/template?kind=opening_stock')->assertDownload('opening_stock-template.csv');
    }

    public function test_invalid_precision_future_dates_and_overflow_are_rejected(): void
    {
        $product = $this->product();
        $this->post('/owner/inventory', $this->stockData($product, '0.5', '10'))->assertSessionHasErrors('rows.0.quantity');
        $this->post('/owner/inventory', $this->stockData($product, '1', '10', ['effective_on' => '2026-11-23']))->assertSessionHasErrors('effective_on');
        $this->post('/owner/inventory', $this->stockData($product, '999999999', '999999999.99'))->assertSessionHasErrors('rows');
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    public function test_signoff_requires_reconciliation_all_counts_and_no_pending_drafts(): void
    {
        $product = $this->product();
        $this->post('/owner/inventory/complete-opening', $this->signoff())->assertSessionHasErrors('opening');
        $batch = $this->draft($this->stockData($product, '0', null));
        $this->post('/owner/inventory/complete-opening', $this->signoff())->assertSessionHasErrors('opening');
        $this->postBatch($batch);
        $this->post('/owner/inventory/complete-opening', [...$this->signoff(), 'expected_debt_total' => '1'])->assertSessionHasErrors('expected_debt_total');
        $this->complete();
        $this->assertNotNull(ShopSettings::query()->findOrFail(1)->opening_completed_at);
        $this->complete();
        $this->assertSame(1, DB::table('audit_events')->where('event', 'opening.signed_off')->count());
        $this->post('/owner/inventory', $this->stockData($product, '0', null))->assertSessionHasErrors('batch');
    }

    public function test_all_opening_batches_share_one_asof_date(): void
    {
        $product = $this->product();
        $this->postBatch($this->draft($this->stockData($product, '0', null)));
        $customer = $this->customer();
        $this->post('/owner/inventory', $this->debtData($customer, '10', ['effective_on' => '2026-11-20']))->assertSessionHasErrors('effective_on');
    }

    public function test_delivery_requires_opening_signoff_full_payment_and_unique_reference(): void
    {
        $product = $this->product();
        $this->post('/owner/inventory', $this->receiptData($product, '2', '10', '20'))->assertSessionHasErrors('batch');
        $this->postBatch($this->draft($this->stockData($product, '0', null)));
        $this->complete();
        $this->post('/owner/inventory', $this->receiptData($product, '2', '10', '19'))->assertSessionHasErrors('amount_paid');
        $data = $this->receiptData($product, '2', '10', '20');
        $batch = $this->draft($data);
        $this->post('/owner/inventory', [...$data, 'submission_key' => (string) Str::uuid()])->assertSessionHasErrors('delivery_reference');
        $this->post('/owner/inventory/'.$batch->id.'/cancel', ['reason' => 'Correction required'])->assertRedirect();
        $corrected = $this->draft([...$data, 'submission_key' => (string) Str::uuid()]);
        $this->postBatch($corrected);
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertSame('cancelled', $batch->refresh()->status);
    }

    public function test_reused_outgoing_mpesa_code_rolls_back_second_receipt(): void
    {
        $product = $this->product();
        $this->postBatch($this->draft($this->stockData($product, '0', null)));
        $this->complete();
        $data = $this->receiptData($product, '1', '10', '10', ['payment_method' => 'mpesa', 'payment_reference' => 'TESTABC123']);
        $first = $this->draft($data);
        $this->postBatch($first);
        $second = $this->draft([...$data, 'submission_key' => (string) Str::uuid(), 'delivery_reference' => 'DELIVERY-2']);
        $this->post('/owner/inventory/'.$second->id.'/post', ['confirm' => true, 'reason' => 'Checked'])->assertSessionHasErrors('batch');
        $this->assertSame('1.000', $product->refresh()->quantity_on_hand);
        $this->assertSame('draft', $second->refresh()->status);
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_posting_rolls_back_all_stock_when_audit_write_fails(): void
    {
        $product = $this->product();
        $batch = $this->draft($this->stockData($product, '3', '10'));
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
        try {
            $this->postBatch($batch);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('0.000', $product->refresh()->quantity_on_hand);
        $this->assertSame('draft', $batch->refresh()->status);
        $this->assertNull(ShopSettings::query()->findOrFail(1)->opening_as_of);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_employee_cannot_access_settings_inventory_imports_or_statements(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $batch = $this->draft($this->stockData($product, '1', '10'));
        $this->actingAs(User::factory()->create());
        foreach (['/owner/shop-settings', '/owner/inventory', '/owner/inventory/create?kind=opening_stock', '/owner/inventory/ledger', '/owner/inventory/'.$batch->id, '/owner/inventory/template?kind=opening_debt', '/owner/customers/'.$customer->id.'/statement'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/owner/inventory', $this->stockData($product, '10', '10'))->assertForbidden();
        $this->post('/owner/inventory/'.$batch->id.'/post', ['confirm' => true, 'reason' => 'Forged'])->assertForbidden();
        $this->post('/owner/inventory/'.$batch->id.'/cancel', ['reason' => 'Forged'])->assertForbidden();
        $this->post('/owner/inventory/complete-opening', $this->signoff())->assertForbidden();
        $this->patch('/owner/shop-settings', ['shop_name' => 'Forged'])->assertForbidden();
        $this->get('/products')->assertInertia(fn (Assert $page) => $page->missing('products.data.0.inventory_value')->missing('products.data.0.average_cost'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('draft', $batch->refresh()->status);
    }

    public function test_owner_settings_are_validated_audited_and_stale_safe(): void
    {
        $data = ['shop_name' => 'New shop identity', 'mpesa_number' => '0712345678', 'mpesa_number_confirmation' => '0712345678', 'version' => 1, 'reason' => 'Owner setup'];
        $this->patch('/owner/shop-settings', $data)->assertRedirect();
        $this->assertDatabaseHas('shop_settings', ['id' => 1, 'mpesa_number' => '0712345678', 'version' => 2]);
        $this->patch('/owner/shop-settings', [...$data, 'version' => 2, 'mpesa_number' => '+254712345679', 'mpesa_number_confirmation' => '+254712345679'])->assertRedirect();
        $event = DB::table('audit_events')->where('event', 'shop.settings_changed')->latest('id')->first();
        $this->assertSame('0712345678', json_decode($event->before_state, true)['mpesa_number']);
        $this->assertSame('+254712345679', json_decode($event->after_state, true)['mpesa_number']);
        $this->patch('/owner/shop-settings', $data)->assertSessionHasErrors('version');
        $this->patch('/owner/shop-settings', [...$data, 'version' => 3, 'mpesa_number' => '123'])->assertSessionHasErrors('mpesa_number');
        $this->get('/owner/shop-settings')->assertInertia(fn (Assert $page) => $page->component('owner/shop-settings')->where('settings.version', 3)->where('name', 'New shop identity'));
    }

    public function test_multi_item_post_rolls_back_if_a_later_product_becomes_inactive(): void
    {
        $first = $this->product();
        $second = $this->product('TEST-PIECE', 'piece');
        $rows = [['sku' => $first->sku, 'unit' => 'bag', 'quantity' => '0', 'unit_cost' => null], ['sku' => $second->sku, 'unit' => 'piece', 'quantity' => '0', 'unit_cost' => null]];
        $this->postBatch($this->draft($this->stockData($first, '0', null, ['rows' => $rows])));
        $this->complete();
        $rows[0]['quantity'] = '1';
        $rows[0]['unit_cost'] = '10';
        $rows[1]['quantity'] = '1';
        $rows[1]['unit_cost'] = '10';
        $receipt = $this->draft($this->receiptData($first, '1', '10', '20', ['rows' => $rows]));
        $second->update(['is_active' => false]);
        $this->post('/owner/inventory/'.$receipt->id.'/post', ['confirm' => true, 'reason' => 'Checked'])->assertSessionHasErrors('batch');
        $this->assertSame('0.000', $first->refresh()->quantity_on_hand);
        $this->assertSame('0.000', $second->refresh()->quantity_on_hand);
        $this->assertSame('draft', $receipt->refresh()->status);
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_csv_opening_debt_uses_existing_customer_and_preserves_due_date(): void
    {
        $customer = $this->customer();
        $data = $this->debtData($customer, '1234.56');
        unset($data['rows']);
        $this->post('/owner/inventory', [...$data, 'csv' => UploadedFile::fake()->createWithContent('debts.csv', "customer_id,amount,due_on\n".$customer->id.",1234.56,2026-11-10\n")])->assertSessionHasNoErrors()->assertRedirect();
        $batch = InventoryBatch::query()->sole();
        $this->assertDatabaseCount('receivables', 0);
        $this->postBatch($batch);
        $this->assertDatabaseHas('receivables', ['customer_id' => $customer->id, 'original_amount_minor' => 123456, 'due_on' => '2026-11-10']);
        $this->post('/owner/inventory/complete-opening', [...$this->signoff(), 'expected_debt_total' => '1234.56'])->assertSessionHasNoErrors()->assertRedirect('/owner/inventory');
    }

    public function test_cancel_is_idempotent_and_posted_batches_cannot_be_cancelled(): void
    {
        $product = $this->product();
        $draft = $this->draft($this->stockData($product, '2', '10'));
        $this->post('/owner/inventory/'.$draft->id.'/cancel', ['reason' => 'Incorrect count'])->assertRedirect();
        $this->post('/owner/inventory/'.$draft->id.'/cancel', ['reason' => 'Retry'])->assertRedirect();
        $this->assertSame(1, DB::table('audit_events')->where('event', 'inventory.draft_cancelled')->count());
        $this->post('/owner/inventory/'.$draft->id.'/post', ['confirm' => true, 'reason' => 'Retry'])->assertSessionHasErrors('batch');
        $posted = $this->draft($this->stockData($product, '2', '10'));
        $this->postBatch($posted);
        $this->post('/owner/inventory/'.$posted->id.'/cancel', ['reason' => 'Delete posted'])->assertSessionHasErrors('batch');
        $this->assertSame('posted', $posted->refresh()->status);
        $this->assertSame('2.000', $product->refresh()->quantity_on_hand);
    }

    public function test_changing_reference_cost_does_not_revalue_posted_stock(): void
    {
        $product = $this->product();
        $this->postBatch($this->draft($this->stockData($product, '3', '10')));
        $product->refresh();
        $this->patch('/products/'.$product->id, [
            'sku' => $product->sku, 'name' => $product->name, 'category_id' => $product->category_id,
            'unit_label' => 'bag', 'quantity_precision' => 0, 'selling_price' => '25.00', 'purchase_cost' => '99.99',
            'reorder_threshold' => '0', 'is_active' => true, 'version' => $product->version, 'reason' => 'Updated reference price',
        ])->assertRedirect('/products');
        $this->patch('/products/'.$product->id, ['inventory_value_minor' => 1, 'average_cost_minor' => 1])->assertSessionHasErrors(['inventory_value_minor', 'average_cost_minor']);
        $this->assertSame(3000, $product->refresh()->inventory_value_minor);
        $this->assertSame(1000, $product->average_cost_minor);
        $this->assertSame(9999, $product->purchase_cost_minor);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_post_requires_explicit_confirmation_and_rows_must_be_a_list(): void
    {
        $product = $this->product();
        $data = $this->stockData($product, '3', '10');
        $this->post('/owner/inventory', [...$data, 'rows' => ['unexpected-key' => $data['rows'][0]]])->assertSessionHasErrors('rows');
        $batch = $this->draft($data);
        $this->post('/owner/inventory/'.$batch->id.'/post', ['reason' => 'No confirmation'])->assertSessionHasErrors('confirm');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->get('/owner/inventory/create?kind=opening_stock')->assertInertia(fn (Assert $page) => $page->component('inventory/create')->where('kind', 'opening_stock')->has('products', 1));
    }

    private function product(string $sku = 'TEST-BAG', string $unit = 'bag', int $precision = 0): Product
    {
        $category = Category::query()->first() ?? Category::create(['name' => 'Materials', 'name_key' => 'materials']);

        return Product::create(['sku' => $sku, 'name' => $sku, 'name_key' => strtolower($sku), 'category_id' => $category->id, 'unit_label' => $unit, 'quantity_precision' => $precision, 'selling_price_minor' => 2000, 'purchase_cost_minor' => 900, 'reorder_threshold' => '0']);
    }

    private function customer(): Customer
    {
        $customer = new Customer(['name' => 'Test customer', 'name_key' => 'test customer', 'contact' => 'Test paper contact', 'contact_key' => 'testpapercontact']);
        $customer->setAttribute('created_by', auth()->id());
        $customer->save();

        return $customer;
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function stockData(Product $product, string $quantity, ?string $cost, array $extra = []): array
    {
        return [...['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Paper stock count', 'rows' => [['sku' => $product->sku, 'unit' => $product->unit_label, 'quantity' => $quantity, 'unit_cost' => $cost]]], ...$extra];
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function receiptData(Product $product, string $quantity, string $cost, string $paid, array $extra = []): array
    {
        return $this->stockData($product, $quantity, $cost, [...['kind' => 'stock_receipt', 'effective_on' => '2026-11-22', 'supplier_name' => 'Test supplier', 'delivery_reference' => 'DELIVERY-1', 'payment_method' => 'cash', 'amount_paid' => $paid], ...$extra]);
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function debtData(Customer $customer, string $amount, array $extra = []): array
    {
        return [...['kind' => 'opening_debt', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Paper debt book', 'rows' => [['customer_id' => $customer->id, 'amount' => $amount, 'due_on' => '2026-11-25']]], ...$extra];
    }

    /** @param array<string, mixed> $data */
    private function draft(array $data): InventoryBatch
    {
        $response = $this->post('/owner/inventory', $data)->assertSessionHasNoErrors();
        $batch = InventoryBatch::query()->where('submission_key', $data['submission_key'])->firstOrFail();
        $response->assertRedirect('/owner/inventory/'.$batch->id);

        return $batch;
    }

    private function postBatch(InventoryBatch $batch): void
    {
        $this->post('/owner/inventory/'.$batch->id.'/post', ['confirm' => true, 'reason' => 'Owner reviewed source'])->assertSessionHasNoErrors()->assertRedirect('/owner/inventory/'.$batch->id);
    }

    /** @return array<string, mixed> */
    private function signoff(): array
    {
        return ['confirm' => true, 'reason' => 'Reconciled against paper records', 'expected_debt_total' => '0.00', 'effective_on' => '2026-11-21'];
    }

    private function complete(): void
    {
        $this->post('/owner/inventory/complete-opening', $this->signoff())->assertSessionHasNoErrors()->assertRedirect('/owner/inventory');
    }
}
