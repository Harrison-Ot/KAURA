<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\FinancialReportService;
use App\Services\InstalmentService;
use App\Services\InventoryService;
use App\Services\OwnerOutflowService;
use App\Services\PaymentVerificationService;
use App\Services\SaleApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $seller;

    private User $collector;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(9, 0));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->seller = User::factory()->create();
        $this->collector = User::factory()->create();
        ShopSettings::findOrFail(1)->update(['shop_name' => 'Test shop', 'mpesa_number' => '0712345678']);
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $this->product = Product::create(['sku' => 'T1', 'name' => 'Tool', 'name_key' => 'tool', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $this->customer = Customer::forceCreate(['name' => 'Customer', 'name_key' => 'customer', 'contact' => '0700000001', 'contact_key' => '0700000001', 'created_by' => $this->owner->id, 'credit_limit_minor' => 10000]);
        $service = app(InventoryService::class);
        $stock = $service->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Test stock', 'rows' => [['sku' => 'T1', 'unit' => 'piece', 'quantity' => '30', 'unit_cost' => '5.00']]]);
        $service->post($this->owner, $stock, 'Reviewed');
        $debt = $service->draft($this->owner, ['kind' => 'opening_debt', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Paper debt', 'rows' => [['customer_id' => $this->customer->id, 'amount' => '10.00', 'due_on' => '2026-11-20']]]);
        $service->post($this->owner, $debt, 'Reviewed');
        $service->completeOpening($this->owner, '10.00', 'Sign off', '2026-11-21');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_report_separates_revenue_collections_costs_purchases_and_withdrawals(): void
    {
        $this->cashSale();
        $this->manualSale('2');
        app(InstalmentService::class)->collect($this->collector, ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => '6.00', 'method' => 'cash']);
        $this->outflow('expense', '2.00');
        $this->outflow('owner_withdrawal', '3.00');
        $service = app(InventoryService::class);
        $batch = $service->draft($this->owner, ['kind' => 'stock_receipt', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-22', 'source_note' => 'Paid delivery', 'supplier_name' => 'Supplier', 'delivery_reference' => 'DEL-1', 'payment_method' => 'cash', 'amount_paid' => '4.00', 'rows' => [['sku' => 'T1', 'unit' => 'piece', 'quantity' => '1', 'unit_cost' => '4.00']]]);
        $service->post($this->owner, $batch, 'Reviewed');
        $report = $this->report();
        $s = $report['summary'];
        $this->assertSame('30.00', $s['gross_sales']);
        $this->assertSame('30.00', $s['net_sales']);
        $this->assertSame('15.00', $s['known_sale_cost']);
        $this->assertSame('15.00', $s['gross_margin_estimate']);
        $this->assertSame('13.00', $s['operating_profit_estimate']);
        $this->assertSame('16.00', $s['confirmed_collections']);
        $this->assertSame('6.00', $s['confirmed_instalments']);
        $this->assertSame('20.00', $s['mpesa_pending']);
        $this->assertSame('3.00', $s['owner_withdrawals']);
        $this->assertSame('4.00', $s['stock_purchases']);
        $this->assertSame('9.00', $s['cash_out']);
        $this->assertSame('7.00', $s['net_cash_movement']);
        $this->assertSame('24.00', $report['current']['outstanding']);
        $this->assertSame('4.00', $report['current']['opening_debt']);
        $this->assertSame('20.00', $report['current']['verification_exposure']);
        $this->assertSame('20.00', $report['current']['unassigned_debt']);
        $this->assertSame('139.00', $report['current']['known_inventory_value']);
        $this->get('/owner/reports/financial')->assertInertia(fn (Assert $page) => $page->component('reports/financial')->where('report.summary.operating_profit_estimate', '13.00'));
    }

    public function test_verification_updates_original_entry_period_without_reassigning_collector(): void
    {
        $sale = $this->manualSale();
        $payment = Payment::where('sale_id', $sale->id)->sole();
        $this->travel(3)->days();
        app(PaymentVerificationService::class)->decide($this->owner, $payment, 'verified', 'Checked message');
        $past = $this->report();
        $this->assertSame('10.00', $past['summary']['mpesa_verified']);
        $this->assertSame('0.00', $past['summary']['mpesa_pending']);
        $seller = collect($past['employees'])->firstWhere('id', $this->seller->id);
        $this->assertSame('10.00', $seller['mpesa_verified']);
        $this->assertCount(1, $past['employees']);
        $today = app(FinancialReportService::class)->build($this->owner, '2026-11-25', '2026-11-25');
        $this->assertSame('0.00', $today['summary']['confirmed_collections']);
        $this->assertSame('10.00', $today['current']['outstanding']);
    }

    public function test_pending_and_disputed_instalments_do_not_reduce_debt_or_inflate_collections(): void
    {
        $payment = app(InstalmentService::class)->collect($this->collector, ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => '6.00', 'method' => 'manual_mpesa', 'mpesa_code' => 'INSTALL123', 'expected_receiving_number' => '0712345678']);
        $report = $this->report();
        $this->assertSame('6.00', $report['summary']['mpesa_pending']);
        $this->assertSame('0.00', $report['summary']['confirmed_collections']);
        $this->assertSame('10.00', $report['current']['outstanding']);
        app(PaymentVerificationService::class)->decide($this->owner, $payment, 'disputed', 'Code not found');
        $report = $this->report();
        $this->assertSame('6.00', $report['summary']['mpesa_disputed']);
        $this->assertSame('6.00', $report['summary']['mpesa_recorded']);
        $this->assertSame('0.00', $report['summary']['net_sales']);
        $this->assertSame('10.00', $report['current']['outstanding']);
    }

    public function test_discount_credit_and_cash_deposit_are_not_double_counted(): void
    {
        $data = ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'payment_method' => 'cash', 'discount_amount' => '1.00', 'credit_amount' => '4.00', 'due_on' => '2026-11-25', 'reason' => 'Terms', 'items' => $this->items()];
        $service = app(SaleApprovalService::class);
        $approval = $service->request($this->seller, $data);
        $service->decide($this->owner, $approval, 'approved', 'Reviewed', 30);
        app(CheckoutService::class)->checkout($this->seller, ['submission_key' => $approval->checkout_key, 'approval_id' => $approval->id, 'customer_id' => $this->customer->id, 'payment_method' => 'cash', 'cash_tendered' => '20.00', 'items' => $this->items()]);
        $report = $this->report();
        $this->assertSame('10.00', $report['summary']['gross_sales']);
        $this->assertSame('1.00', $report['summary']['discounts']);
        $this->assertSame('9.00', $report['summary']['net_sales']);
        $this->assertSame('5.00', $report['summary']['cash_confirmed']);
        $this->assertSame('4.00', $report['summary']['gross_margin_estimate']);
        $this->assertSame('4.00', $report['current']['credit_sale_debt']);
        $this->assertSame('10.00', $report['current']['overdue_credit_and_opening']);
    }

    public function test_unknown_historical_costs_disable_estimates_even_after_catalogue_changes(): void
    {
        $this->product->forceFill(['inventory_value_minor' => null, 'average_cost_minor' => null])->save();
        $this->cashSale();
        $this->product->refresh()->forceFill(['purchase_cost_minor' => 500, 'inventory_value_minor' => 14500])->save();
        $report = $this->report();
        $this->assertSame(1, $report['summary']['unknown_cost_sales']);
        $this->assertNull($report['summary']['gross_margin_estimate']);
        $this->assertNull($report['summary']['operating_profit_estimate']);
        $this->assertSame('0.00', $report['summary']['known_sale_cost']);
        $csv = $this->get('/owner/reports/financial/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('gross_margin_estimate,INCOMPLETE,KES', $csv);
    }

    public function test_negative_estimates_and_cash_movement_keep_their_exact_sign_and_cents(): void
    {
        $this->product->update(['selling_price_minor' => 1]);
        app(CheckoutService::class)->checkout($this->seller, ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => '0.01', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '0.01']]]);
        $this->outflow('expense', '0.02');
        $report = $this->report();
        $this->assertSame('-4.99', $report['summary']['gross_margin_estimate']);
        $this->assertSame('-5.01', $report['summary']['operating_profit_estimate']);
        $this->assertSame('-0.01', $report['summary']['net_cash_movement']);
    }

    public function test_account_activity_separates_seller_and_instalment_collector_and_keeps_inactive_accounts(): void
    {
        $this->cashSale();
        app(InstalmentService::class)->collect($this->collector, ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => '6.00', 'method' => 'cash']);
        $this->seller->forceFill(['is_active' => false])->save();
        $rows = collect($this->report()['employees']);
        $this->assertSame('10.00', $rows->firstWhere('id', $this->seller->id)['net_sales']);
        $this->assertFalse($rows->firstWhere('id', $this->seller->id)['active']);
        $this->assertSame('0.00', $rows->firstWhere('id', $this->collector->id)['net_sales']);
        $this->assertSame('6.00', $rows->firstWhere('id', $this->collector->id)['cash_confirmed']);
    }

    public function test_owner_only_report_export_and_domain_service(): void
    {
        $this->actingAs($this->seller)->get('/owner/reports/financial')->assertForbidden();
        $this->get('/owner/reports/financial/export')->assertForbidden();
        $this->expectException(HttpException::class);
        app(FinancialReportService::class)->build($this->seller, '2026-11-22', '2026-11-22');
    }

    public function test_dates_are_inclusive_bounded_and_follow_nairobi_midnight(): void
    {
        $this->cashSale();
        $this->travelTo(now()->setTime(21, 30));
        $this->cashSale();
        $this->get('/owner/reports/financial')->assertInertia(fn (Assert $page) => $page->where('report.from', '2026-11-23')->where('report.summary.sale_count', 1));
        $this->get('/owner/reports/financial?from=2026-11-22&to=2026-11-23')->assertInertia(fn (Assert $page) => $page->where('report.summary.sale_count', 2));
        foreach (['from=2026-11-23&to=2026-11-22', 'from=2024-01-01&to=2026-11-22', 'from=2026-11-22&to=2026-11-24'] as $query) {
            $this->get('/owner/reports/financial?'.$query)->assertSessionHasErrors('to');
        }
    }

    public function test_csv_exports_snapshot_metrics_and_escapes_account_names(): void
    {
        $this->seller->update(['name' => '=HYPERLINK("bad")']);
        $this->cashSale();
        $csv = $this->get('/owner/reports/financial/export')->assertOk()->assertDownload('kaura-financial-2026-11-22-to-2026-11-22.csv')->streamedContent();
        $rows = array_map(fn (string $row) => str_getcsv($row, ',', '"', ''), preg_split('/\r?\n/', trim($csv)));
        $gross = collect($rows)->first(fn (array $row) => $row[0] === 'summary' && $row[3] === 'gross_sales');
        $this->assertSame('10.00', $gross[4]);
        $this->assertSame('KES', $gross[5]);
        $employee = collect($rows)->first(fn (array $row) => $row[0] === 'employee');
        $this->assertSame("'=HYPERLINK(\"bad\")", $employee[2]);
        $this->assertDatabaseHas('audit_events', ['event' => 'financial_report.exported', 'actor_id' => $this->owner->id]);
    }

    public function test_large_report_totals_never_overflow_native_integers(): void
    {
        $rows = [];
        for ($i = 0; $i < 1100; $i++) {
            $rows[] = ['reference' => 'TEST-'.$i, 'posting_key' => 'test:'.$i, 'purpose' => 'instalment', 'handled_by' => $this->collector->id, 'method' => 'cash', 'status' => 'verified', 'amount_minor' => 9000000000000000, 'business_date' => '2026-11-22', 'received_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('payments')->insert($chunk);
        }
        $this->assertSame('99000000000000000.00', $this->report()['summary']['confirmed_collections']);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(FinancialReportService::class)->build($this->owner, '2026-11-22', '2026-11-22');
    }

    /** @return list<array<string, mixed>> */
    private function items(string $quantity = '1'): array
    {
        return [['product_id' => $this->product->id, 'quantity' => $quantity, 'quoted_price' => '10.00']];
    }

    private function cashSale(): Sale
    {
        return app(CheckoutService::class)->checkout($this->seller, ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => '20.00', 'items' => $this->items()]);
    }

    private function manualSale(string $quantity = '1'): Sale
    {
        return app(CheckoutService::class)->checkout($this->seller, ['submission_key' => (string) Str::uuid(), 'payment_method' => 'manual_mpesa', 'mpesa_code' => 'REPORT123', 'mpesa_amount' => $quantity === '1' ? '10.00' : '20.00', 'expected_receiving_number' => '0712345678', 'items' => $this->items($quantity)]);
    }

    private function outflow(string $kind, string $amount): void
    {
        app(OwnerOutflowService::class)->record($this->owner, ['submission_key' => (string) Str::uuid(), 'kind' => $kind, 'category' => $kind === 'expense' ? 'rent' : null, 'amount' => $amount, 'payment_method' => 'cash', 'recipient' => 'Test recipient', 'reason' => 'Test payment']);
    }
}
