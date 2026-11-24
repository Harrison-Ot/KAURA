<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\OwnerOutflowService;
use App\Services\SaleVoidService;
use App\Services\VoidResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->employee = User::factory()->create();
        ShopSettings::findOrFail(1)->update(['shop_name' => 'Test hardware', 'mpesa_number' => '0712345678']);
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $this->product = Product::create(['sku' => 'TOOL', 'name' => 'Tool', 'name_key' => 'tool', 'category_id' => $category->id, 'unit_label' => 'kg', 'quantity_precision' => 3, 'selling_price_minor' => 1001, 'reorder_threshold' => '2']);
        $this->customer = Customer::forceCreate(['name' => 'Customer', 'name_key' => 'customer', 'contact' => '0700000001', 'contact_key' => '0700000001', 'created_by' => $this->owner->id, 'credit_limit_minor' => 100000]);
        $inventory = app(InventoryService::class);
        $day = now('Africa/Nairobi')->subDay()->toDateString();
        $batch = $inventory->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => $day, 'source_note' => 'Independent counted stock', 'rows' => [['sku' => 'TOOL', 'unit' => 'kg', 'quantity' => '10', 'unit_cost' => '5.00']]]);
        $inventory->post($this->owner, $batch, 'Count reviewed');
        $batch = $inventory->draft($this->owner, ['kind' => 'opening_debt', 'submission_key' => (string) Str::uuid(), 'effective_on' => $day, 'source_note' => 'Paper debt', 'rows' => [['customer_id' => $this->customer->id, 'amount' => '20.00', 'due_on' => $day]]]);
        $inventory->post($this->owner, $batch, 'Debt reviewed');
        $inventory->completeOpening($this->owner, '20.00', 'Reconciled to paper', $day);
        $this->actingAs($this->owner);
    }

    public function test_partial_returns_preserve_sale_and_exact_residuals_and_reject_overreturn(): void
    {
        $sale = $this->sale('1');
        $data = $this->returnData($sale->id, $sale->items->sole()->id, '0.333');
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('shop_operations', 1);
        $this->assertDatabaseHas('shop_operations', ['amount_minor' => 333, 'cash_amount_minor' => 333]);
        $this->post('/owner/operations', $this->returnData($sale->id, $sale->items->sole()->id, '0.667'))->assertSessionHasNoErrors();
        $this->assertSame(1001, (int) DB::table('shop_operations')->sum('amount_minor'));
        $this->assertSame(500, (int) DB::table('shop_operation_items')->sum('cost_minor'));
        $this->assertSame('10.000', $this->product->fresh()->quantity_on_hand);
        $this->assertSame('posted', $sale->fresh()->getAttribute('status'));
        $this->post('/owner/operations', $this->returnData($sale->id, $sale->items->sole()->id, '0.001'))->assertSessionHasErrors('operation');
        $this->post('/sales/'.$sale->id.'/void', ['reason' => 'Duplicate reversal'])->assertSessionHasErrors();
        $report = app(FinancialReportService::class)->build($this->owner, now('Africa/Nairobi')->toDateString(), now('Africa/Nairobi')->toDateString());
        $this->assertSame('0.00', $report['summary']['net_sales']);
        $this->assertSame('0.00', $report['summary']['known_sale_cost']);
        $this->assertSame('10.01', $report['summary']['refunds']);
    }

    public function test_damaged_return_records_condition_without_creating_sellable_stock_or_reversing_cost(): void
    {
        $sale = $this->sale('2');
        $data = $this->returnData($sale->id, $sale->items->sole()->id, '1');
        $data['items'][0]['disposition'] = 'damaged';
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->assertSame('8.000', $this->product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('shop_operation_items', ['disposition' => 'damaged', 'quantity' => 1]);
        $day = now('Africa/Nairobi')->toDateString();
        $report = app(FinancialReportService::class)->build($this->owner, $day, $day);
        $this->assertSame('10.00', $report['summary']['known_sale_cost']);
        $this->assertSame('10.01', $report['summary']['net_sales']);
    }

    public function test_writeoff_and_retained_credit_offset_do_not_create_collections_and_bind_customer(): void
    {
        $sale = $this->sale('1');
        $void = app(SaleVoidService::class)->void($this->owner, $sale, 'Customer changed order');
        app(SaleVoidService::class)->decideStock($this->owner, $void, 'restocked', 'Goods received');
        app(VoidResolutionService::class)->resolve($this->owner, $void, ['submission_key' => (string) Str::uuid(), 'kind' => 'customer_credit', 'customer_id' => $this->customer->id, 'recipient' => 'Customer', 'reason' => 'Retain for future purchase']);
        $credit = DB::table('customer_credits')->sole();
        $debt = DB::table('receivables')->where('origin', 'opening_balance')->sole();
        $data = ['kind' => 'credit_offset', 'submission_key' => (string) Str::uuid(), 'customer_credit_id' => $credit->id, 'receivable_id' => $debt->id, 'amount' => '10.01', 'reason' => 'Customer credit applied to oldest paper debt', 'confirm' => true];
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('receivables', ['id' => $debt->id, 'remaining_amount_minor' => 999]);
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'remaining_amount_minor' => 0]);
        $this->post('/owner/operations', ['kind' => 'write_off', 'submission_key' => (string) Str::uuid(), 'receivable_id' => $debt->id, 'amount' => '9.99', 'reason' => 'Owner approved irrecoverable paper debt', 'confirm' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('receivables', ['id' => $debt->id, 'remaining_amount_minor' => 0]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_expense_reversal_and_retries_adjust_profit_and_cash_without_mutating_original(): void
    {
        $outflow = app(OwnerOutflowService::class)->record($this->owner, ['submission_key' => (string) Str::uuid(), 'kind' => 'expense', 'amount' => '2.50', 'category' => 'transport', 'payment_method' => 'cash', 'recipient' => 'Driver', 'reason' => 'Delivery expense']);
        $data = ['submission_key' => (string) Str::uuid(), 'kind' => 'reversal', 'cash_outflow_id' => $outflow->id, 'payment_method' => 'cash', 'recipient' => 'Driver returned cash', 'reason' => 'Delivery cancelled and cash returned', 'confirm' => true];
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_outflows', ['id' => $outflow->id, 'amount_minor' => 250]);
        $this->assertDatabaseCount('shop_operations', 1);
        $this->post('/owner/operations', [...$data, 'submission_key' => (string) Str::uuid()])->assertSessionHasErrors('operation');
        $day = now('Africa/Nairobi')->toDateString();
        $report = app(FinancialReportService::class)->build($this->owner, $day, $day);
        $this->assertSame('0.00', $report['summary']['operating_expenses']);
        $this->assertSame('0.00', $report['summary']['net_cash_movement']);
    }

    public function test_cash_handover_scopes_employees_and_flags_later_activity(): void
    {
        $this->sale('1', $this->employee);
        $day = now('Africa/Nairobi')->toDateString();
        $this->actingAs($this->employee)->get('/my-day')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.expected_minor', '1001'));
        $this->post('/my-day', ['day' => $day, 'amount' => '10.01', 'note' => 'Counter cash handed over'])->assertSessionHasNoErrors();
        $id = DB::table('cash_handovers')->sole()->id;
        $this->post('/owner/cash-handovers/'.$id.'/review', ['received' => '10.00', 'note' => 'One cent short', 'confirm' => true])->assertForbidden();
        $this->actingAs($this->owner)->post('/owner/cash-handovers/'.$id.'/review', ['received' => '10.00', 'note' => 'One cent short', 'confirm' => true])->assertSessionHasNoErrors();
        $this->sale('1', $this->employee);
        $this->actingAs($this->employee)->get('/my-day')->assertInertia(fn (Assert $page) => $page->where('rows.0.stale', true));
    }

    public function test_catalogue_import_preview_post_and_atomic_duplicate_checks(): void
    {
        $csv = "sku,name,category,unit,precision,selling_price,purchase_cost,reorder_threshold,barcode\nNEW1,New bag,Cement,bag,0,100.00,,2,\nNEW2,New kg,Cement,kg,3,10.00,5.00,2.5,\n";
        $this->post('/owner/catalogue-import', ['submission_key' => (string) Str::uuid(), 'source_note' => 'Paper catalogue', 'csv' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('products', ['sku' => 'NEW1']);
        $id = DB::table('catalogue_imports')->sole()->id;
        $this->post('/owner/catalogue-import/'.$id.'/post', ['confirm' => true])->assertSessionHasNoErrors();
        $this->post('/owner/catalogue-import/'.$id.'/post', ['confirm' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['sku' => 'NEW1', 'quantity_on_hand' => 0, 'purchase_cost_minor' => null]);
        $this->assertDatabaseHas('products', ['sku' => 'NEW2', 'quantity_precision' => 3]);
        $this->assertDatabaseCount('products', 3);
    }

    public function test_owner_reports_exports_and_operation_pages_and_employee_denials(): void
    {
        $this->get('/')->assertOk();
        $this->get('/owner/operations')->assertOk();
        $this->get('/owner/operations/create?customer_id='.$this->customer->id)->assertOk();
        $this->get('/owner/catalogue-import')->assertOk();
        $this->get('/owner/reports/financial')->assertInertia(fn (Assert $page) => $page->has('detail.daily')->has('detail.aging')->has('detail.products'));
        foreach (['products', 'debts', 'variance', 'operations', 'handovers'] as $dataset) {
            $this->get('/owner/reports/export?dataset='.$dataset)->assertOk()->streamedContent();
        }
        $this->actingAs($this->employee);
        foreach (['/owner/operations', '/owner/catalogue-import', '/owner/reports/export?dataset=debts'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_operation_audit_failure_rolls_back_financial_and_stock_changes(): void
    {
        $sale = $this->sale('1');
        DB::statement("CREATE TRIGGER fail_operation_audit BEFORE INSERT ON audit_events WHEN NEW.event LIKE 'shop_operation.%' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->post('/owner/operations', $this->returnData($sale->id, $sale->items->sole()->id, '1'))->assertServerError();
        $this->assertDatabaseCount('shop_operations', 0);
        $this->assertDatabaseCount('shop_operation_items', 0);
        $this->assertSame('9.000', $this->product->fresh()->quantity_on_hand);
    }

    public function test_damaged_return_is_recorded_separately_without_creating_sellable_stock(): void
    {
        $sale = $this->sale('2');
        $data = $this->returnData($sale->id, $sale->items->sole()->id, '1');
        $data['items'][0]['disposition'] = 'damaged';
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->assertSame('8.000', $this->product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('shop_operation_items', ['disposition' => 'damaged', 'quantity' => 1]);
        $report = app(FinancialReportService::class)->build($this->owner, now('Africa/Nairobi')->toDateString(), now('Africa/Nairobi')->toDateString());
        $this->assertSame('10.01', $report['summary']['net_sales']);
        $this->assertSame('10.00', $report['summary']['known_sale_cost']);
        $this->assertSame('0.01', $report['summary']['gross_margin_estimate']);
        $this->assertSame('10.01', $report['summary']['refunds']);
    }

    public function test_delivery_reversal_requires_no_later_stock_activity_and_restores_value_once(): void
    {
        $inventory = app(InventoryService::class);
        $batch = $inventory->draft($this->owner, ['kind' => 'stock_receipt', 'submission_key' => (string) Str::uuid(), 'effective_on' => now('Africa/Nairobi')->toDateString(), 'source_note' => 'Wrong delivery', 'supplier_name' => 'Supplier', 'delivery_reference' => 'DELIVERY-1', 'payment_method' => 'cash', 'amount_paid' => '12.00', 'rows' => [['sku' => 'TOOL', 'unit' => 'kg', 'quantity' => '2', 'unit_cost' => '6.00']]]);
        $inventory->post($this->owner, $batch, 'Received and paid');
        $outflow = DB::table('cash_outflows')->where('batch_id', $batch->id)->sole();
        $data = ['kind' => 'reversal', 'submission_key' => (string) Str::uuid(), 'cash_outflow_id' => $outflow->id, 'payment_method' => 'cash', 'recipient' => 'Supplier refund received', 'reason' => 'Goods returned and supplier refunded full payment', 'confirm' => true];
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->assertSame('10.000', $this->product->fresh()->quantity_on_hand);
        $this->assertSame(5000, $this->product->fresh()->inventory_value_minor);
        $this->assertDatabaseHas('cash_outflows', ['id' => $outflow->id, 'amount_minor' => 1200]);
        $this->assertDatabaseCount('shop_operations', 1);
        $this->post('/owner/operations', [...$data, 'submission_key' => (string) Str::uuid()])->assertSessionHasErrors('operation');
    }

    public function test_retained_credit_redemption_creates_no_fictitious_cash_and_retries_are_safe(): void
    {
        $sale = $this->sale('1');
        $void = app(SaleVoidService::class)->void($this->owner, $sale, 'Exchange requested');
        app(SaleVoidService::class)->decideStock($this->owner, $void, 'restocked', 'Goods accounted for');
        app(VoidResolutionService::class)->resolve($this->owner, $void, ['submission_key' => (string) Str::uuid(), 'kind' => 'customer_credit', 'customer_id' => $this->customer->id, 'recipient' => 'Customer', 'reason' => 'Credit retained for exchange']);
        $credit = DB::table('customer_credits')->sole();
        $data = ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'customer_id' => $this->customer->id, 'customer_credit_id' => $credit->id, 'retained_credit_amount' => '10.01', 'cash_tendered' => '0.00', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.01']]];
        $this->post('/sales', $data)->assertSessionHasNoErrors();
        $this->post('/sales', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'remaining_amount_minor' => 0]);
        $this->assertDatabaseHas('shop_operations', ['kind' => 'credit_redemption', 'amount_minor' => 1001, 'cash_amount_minor' => 0]);
        $redeemed = Sale::query()->latest('id')->firstOrFail();
        $this->get('/sales/'.$redeemed->id.'/receipt')->assertOk()->assertSee('credit redemption');
        $this->get('/sales/'.$redeemed->id)->assertOk();
        $this->actingAs($this->employee)->post('/sales', [...$data, 'submission_key' => (string) Str::uuid()])->assertSessionHasErrors('customer_credit_id');
    }

    public function test_operation_mpesa_codes_cannot_be_reused_in_checkout(): void
    {
        $sale = $this->sale('1');
        $data = [...$this->returnData($sale->id, $sale->items->sole()->id, '1'), 'payment_method' => 'mpesa', 'payment_reference' => 'RETURN123'];
        $this->post('/owner/operations', $data)->assertSessionHasNoErrors();
        $this->post('/sales', ['submission_key' => (string) Str::uuid(), 'payment_method' => 'manual_mpesa', 'expected_receiving_number' => '0712345678', 'mpesa_amount' => '10.01', 'mpesa_code' => 'RETURN123', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.01']]])->assertSessionHasErrors('mpesa_code');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_later_cash_postings_flag_owner_review_as_stale(): void
    {
        $day = now('Africa/Nairobi')->toDateString();
        $this->sale('1', $this->employee);
        $this->actingAs($this->employee)->post('/my-day', ['day' => $day, 'amount' => '10.01', 'note' => 'First handover'])->assertSessionHasNoErrors();
        $id = DB::table('cash_handovers')->sole()->id;
        $this->actingAs($this->owner)->post('/owner/cash-handovers/'.$id.'/review', ['received' => '10.01', 'note' => 'Counted', 'confirm' => true])->assertSessionHasNoErrors();
        $this->sale('1', $this->employee);
        $this->actingAs($this->employee)->get('/my-day?day='.$day)->assertInertia(fn (Assert $page) => $page->where('rows.0.stale', true));
    }

    public function test_invalid_bulk_row_cannot_partially_create_catalogue(): void
    {
        $csv = "sku,name,category,unit,precision,selling_price,purchase_cost,reorder_threshold,barcode\nNEW1,Valid,Tools,piece,0,10.00,5.00,2,\nNEW2,Invalid,Tools,piece,0,10.00,5.00,2.5,\n";
        $this->post('/owner/catalogue-import', ['submission_key' => (string) Str::uuid(), 'source_note' => 'Invalid paper file', 'csv' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv)])->assertSessionHasErrors();
        $this->assertDatabaseCount('catalogue_imports', 0);
        $this->assertDatabaseMissing('products', ['sku' => 'NEW1']);
    }

    private function sale(string $quantity, ?User $actor = null): Sale
    {
        return app(CheckoutService::class)->checkout($actor ?? $this->owner, ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'customer_id' => $this->customer->id, 'cash_tendered' => '100.00', 'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'quoted_price' => '10.01']]]);
    }

    /** @return array<string, mixed> */
    private function returnData(int $sale, int $item, string $quantity): array
    {
        return ['submission_key' => (string) Str::uuid(), 'kind' => 'return', 'sale_id' => $sale, 'items' => [['sale_item_id' => $item, 'quantity' => $quantity, 'disposition' => 'restock']], 'payment_method' => 'cash', 'recipient' => 'Original customer', 'reason' => 'Goods received and reviewed by owner', 'confirm' => true];
    }
}
