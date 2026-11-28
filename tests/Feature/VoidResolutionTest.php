<?php

namespace Tests\Feature;

use App\Models\CashOutflow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Models\User;
use App\Models\VoidResolution;
use App\Services\CheckoutService;
use App\Services\FinancialReportService;
use App\Services\InventoryService;
use App\Services\OwnerOutflowService;
use App\Services\SaleVoidService;
use App\Services\VoidResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VoidResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(9, 0));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->employee = User::factory()->create();
        ShopSettings::findOrFail(1)->update(['shop_name' => 'Test shop', 'mpesa_number' => '0712345678']);
        $this->customer = Customer::forceCreate(['name' => 'Original Customer', 'name_key' => 'original customer', 'contact' => '0700000001', 'contact_key' => '0700000001', 'created_by' => $this->owner->id]);
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $this->product = Product::create(['sku' => 'T1', 'name' => 'Tool', 'name_key' => 'tool', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $inventory = app(InventoryService::class);
        $batch = $inventory->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-22', 'source_note' => 'Opening', 'rows' => [['sku' => 'T1', 'unit' => 'piece', 'quantity' => '10', 'unit_cost' => '5.00']]]);
        $inventory->post($this->owner, $batch, 'Reviewed');
        $inventory->completeOpening($this->owner, '0.00', 'Sign off', '2026-11-22');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_cash_refund_posts_once_without_rewriting_original_payment_or_expenses(): void
    {
        $void = $this->void();
        $data = $this->data();
        $this->resolve($void, $data);
        $this->resolve($void, $data);
        $resolution = VoidResolution::sole();
        $this->assertSame(1000, $resolution->amount_minor);
        $this->assertDatabaseHas('cash_outflows', ['kind' => 'refund', 'void_resolution_id' => $resolution->id, 'amount_minor' => 1000, 'effective_on' => '2026-11-23']);
        $this->assertDatabaseHas('payments', ['amount_minor' => 1000, 'status' => 'verified']);
        $this->assertSame('9.000', $this->product->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('customer_credits', 0);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'sale_void.funds_resolved')->count());
        $this->get('/owner/sale-voids')->assertInertia(fn (Assert $page) => $page->has('voids.data', 0));
        $this->get('/owner/sale-voids?status=all')->assertInertia(fn (Assert $page) => $page->where('voids.data.0.resolution.kind', 'refund'));
        $this->get('/sales/'.$void->sale_id.'/receipt')->assertOk()->assertSee('Owner refund recorded')->assertDontSee('No refund is confirmed');
        $report = $this->report();
        $this->assertSame('10.00', $report['summary']['confirmed_collections']);
        $this->assertSame('10.00', $report['summary']['refunds']);
        $this->assertSame('0.00', $report['summary']['net_collections_after_refunds']);
        $this->assertSame('0.00', $report['summary']['operating_expenses']);
        $this->assertSame('0.00', $report['current']['void_confirmed_funds_held']);
        $this->get('/owner/outflows?kind=refund')->assertInertia(fn (Assert $page) => $page->where('totals.refund', '10.00')->where('outflows.data.0.void_sale_id', $void->sale_id));
        $this->get('/owner/outflows/'.CashOutflow::sole()->id)->assertOk();
    }

    public function test_retained_credit_is_a_separate_customer_liability_not_money_out_or_debt_offset(): void
    {
        $void = $this->void();
        $data = $this->creditData();
        $this->resolve($void, $data);
        $this->resolve($void, $data);
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->assertDatabaseCount('customer_credits', 1);
        $this->assertDatabaseHas('customer_credits', ['customer_id' => $this->customer->id, 'original_amount_minor' => 1000, 'remaining_amount_minor' => 1000]);
        $this->get('/owner/customers/'.$this->customer->id.'/statement')->assertInertia(fn (Assert $page) => $page->where('credits.0.remaining', '10.00')->has('receivables', 0));
        $report = $this->report();
        $this->assertSame('10.00', $report['current']['retained_customer_credit']);
        $this->assertSame('0.00', $report['current']['void_confirmed_funds_held']);
        $this->assertSame('0.00', $report['summary']['cash_out']);
        $this->assertSame('10.00', $report['summary']['confirmed_collections']);
        $this->customer->update(['name' => 'Changed name']);
        $this->assertSame('Original Customer', VoidResolution::sole()->getAttribute('customer_snapshot')['name']);
    }

    public function test_stock_review_and_confirmed_claims_are_required_before_funds_resolution(): void
    {
        $void = $this->void(review: false);
        $this->post($this->url($void), $this->data())->assertSessionHasErrors('resolution');
        $pending = $this->void(manual: true);
        $this->post($this->url($pending), $this->data())->assertSessionHasErrors('resolution');
        $this->assertDatabaseCount('void_resolutions', 0);
        $this->assertDatabaseCount('cash_outflows', 0);
    }

    public function test_named_sale_credit_cannot_be_transferred_to_another_customer(): void
    {
        $void = $this->void();
        $other = Customer::forceCreate(['name' => 'Other', 'name_key' => 'other', 'contact' => '0700000002', 'contact_key' => '0700000002', 'created_by' => $this->owner->id]);
        $this->post($this->url($void), [...$this->creditData(), 'customer_id' => $other->id])->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('customer_credits', 0);
    }

    public function test_mpesa_refund_code_is_normalized_unique_and_kept_separate_from_incoming_codes(): void
    {
        $void = $this->void();
        app(OwnerOutflowService::class)->record($this->owner, ['submission_key' => (string) Str::uuid(), 'kind' => 'expense', 'category' => 'transport', 'amount' => '1.00', 'payment_method' => 'mpesa', 'payment_reference' => 'EXISTING123', 'recipient' => 'Rider', 'reason' => 'Transport']);
        $data = [...$this->data(), 'payment_method' => 'mpesa', 'payment_reference' => 'EXISTING123'];
        $this->post($this->url($void), $data)->assertSessionHasErrors('payment_reference');
        Payment::where('sale_id', $void->sale_id)->update(['mpesa_code' => 'INCOMING123']);
        $this->post($this->url($void), [...$data, 'payment_reference' => 'INCOMING123'])->assertSessionHasErrors('payment_reference');
        $this->resolve($void, [...$data, 'payment_reference' => '  refund123 ']);
        $this->assertDatabaseHas('cash_outflows', ['kind' => 'refund', 'payment_reference' => 'REFUND123']);
    }

    public function test_owner_only_resolution_and_prohibited_amount_or_missing_confirmation(): void
    {
        $void = $this->void();
        $this->post($this->url($void), [...$this->data(), 'amount' => '1.00'])->assertSessionHasErrors('amount');
        $this->post($this->url($void), [...$this->data(), 'confirm' => false])->assertSessionHasErrors('confirm');
        $this->actingAs($this->employee)->post($this->url($void), $this->data())->assertForbidden();
        $this->expectException(HttpException::class);
        app(VoidResolutionService::class)->resolve($this->employee, $void, $this->data());
    }

    public function test_changed_resolution_cannot_pay_or_credit_the_same_funds_twice(): void
    {
        $void = $this->void();
        $data = $this->data();
        $this->resolve($void, $data);
        $this->post($this->url($void), [...$data, 'reason' => 'Changed terms'])->assertSessionHasErrors('resolution');
        $this->post($this->url($void), $this->creditData())->assertSessionHasErrors('resolution');
        $this->assertDatabaseCount('void_resolutions', 1);
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertDatabaseCount('customer_credits', 0);
    }

    public function test_refund_audit_failure_rolls_back_resolution_and_payout(): void
    {
        $void = $this->void();
        $this->failAudit();
        try {
            $this->post($this->url($void), $this->data());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('void_resolutions', 0);
        $this->assertDatabaseCount('cash_outflows', 0);
    }

    public function test_credit_audit_failure_rolls_back_credit_and_customer_version(): void
    {
        $void = $this->void();
        $version = $this->customer->refresh()->version;
        $this->failAudit();
        try {
            $this->post($this->url($void), $this->creditData());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('void_resolutions', 0);
        $this->assertDatabaseCount('customer_credits', 0);
        $this->assertSame($version, $this->customer->refresh()->version);
    }

    public function test_migration_roundtrip_preserves_void_and_refuses_after_resolution(): void
    {
        $void = $this->void();
        $migration = require database_path('migrations/2026_11_23_000010_create_void_resolutions.php');
        $payoutMigration = require database_path('migrations/2026_11_23_000011_create_customer_credit_payouts.php');
        $payoutMigration->down();
        $migration->down();
        $migration->up();
        $payoutMigration->up();
        $this->assertDatabaseCount('sale_voids', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->resolve($void, $this->data());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_retained_credit_can_be_paid_out_once_without_a_second_resolution_or_collection(): void
    {
        $void = $this->void();
        $this->resolve($void, $this->creditData());
        $credit = DB::table('customer_credits')->sole();
        $data = $this->payoutData();
        $url = '/owner/customer-credits/'.$credit->id.'/payout';
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('void_resolutions', 1);
        $this->assertDatabaseCount('customer_credit_payouts', 1);
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'remaining_amount_minor' => 0]);
        $report = $this->report();
        $this->assertSame('0.00', $report['current']['retained_customer_credit']);
        $this->assertSame('10.00', $report['summary']['refunds']);
        $this->assertSame('10.00', $report['summary']['confirmed_collections']);
        $this->get('/sales/'.$void->sale_id.'/receipt')->assertOk()->assertSee('Retained credit later paid out');
        $this->get('/owner/customers/'.$this->customer->id.'/statement')->assertInertia(fn (Assert $page) => $page->where('credits.0.remaining', '0.00')->where('credits.0.payout_outflow_id', CashOutflow::sole()->id));
        $this->post($url, [...$data, 'reason' => 'Changed terms'])->assertSessionHasErrors('payout');
        $this->assertDatabaseCount('cash_outflows', 1);
    }

    public function test_credit_mpesa_payout_checks_code_reuse_and_normalizes_new_code(): void
    {
        $this->resolve($this->void(), $this->creditData());
        $credit = DB::table('customer_credits')->sole();
        Payment::query()->update(['mpesa_code' => 'INCOMING123']);
        $url = '/owner/customer-credits/'.$credit->id.'/payout';
        $data = [...$this->payoutData(), 'payment_method' => 'mpesa', 'payment_reference' => 'INCOMING123'];
        $this->post($url, $data)->assertSessionHasErrors('payment_reference');
        $this->post($url, [...$data, 'payment_reference' => ' payback123 '])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_outflows', ['kind' => 'refund', 'payment_method' => 'mpesa', 'payment_reference' => 'PAYBACK123']);
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'remaining_amount_minor' => 0]);
    }

    public function test_credit_payout_requires_owner_confirmation_and_derived_amount(): void
    {
        $this->resolve($this->void(), $this->creditData());
        $credit = DB::table('customer_credits')->sole();
        $url = '/owner/customer-credits/'.$credit->id.'/payout';
        $this->post($url, [...$this->payoutData(), 'amount' => '1.00'])->assertSessionHasErrors('amount');
        $this->post($url, [...$this->payoutData(), 'confirm' => false])->assertSessionHasErrors('confirm');
        $this->actingAs($this->employee)->post($url, $this->payoutData())->assertForbidden();
        $this->assertDatabaseCount('customer_credit_payouts', 0);
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'remaining_amount_minor' => 1000]);
    }

    public function test_credit_payout_audit_failure_restores_balance_and_creates_no_outflow(): void
    {
        $this->resolve($this->void(), $this->creditData());
        $credit = DB::table('customer_credits')->sole();
        $version = $this->customer->refresh()->version;
        $this->failAudit();
        try {
            $this->post('/owner/customer-credits/'.$credit->id.'/payout', $this->payoutData());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->assertDatabaseCount('customer_credit_payouts', 0);
        $this->assertSame($version, $this->customer->refresh()->version);
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'remaining_amount_minor' => 1000]);
    }

    public function test_payout_migration_roundtrip_preserves_credit_and_refuses_paid_history(): void
    {
        $this->resolve($this->void(), $this->creditData());
        $credit = DB::table('customer_credits')->sole();
        $migration = require database_path('migrations/2026_11_23_000011_create_customer_credit_payouts.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('customer_credits', ['id' => $credit->id, 'remaining_amount_minor' => 1000]);
        $this->post('/owner/customer-credits/'.$credit->id.'/payout', $this->payoutData())->assertSessionHasNoErrors();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /** @return array<string, mixed> */
    private function payoutData(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'recipient' => 'Original Customer', 'reason' => 'Returned retained credit with customer acknowledgement', 'confirm' => true];
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'kind' => 'refund', 'payment_method' => 'cash', 'recipient' => 'Original Customer', 'reason' => 'Full amount returned; customer acknowledged', 'confirm' => true];
    }

    /** @return array<string, mixed> */
    private function creditData(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'kind' => 'customer_credit', 'customer_id' => $this->customer->id, 'recipient' => 'Original Customer', 'reason' => 'Customer agreed to retain full credit', 'confirm' => true];
    }

    private function void(bool $review = true, bool $manual = false): SaleVoid
    {
        $sale = app(CheckoutService::class)->checkout($this->employee, ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'payment_method' => $manual ? 'manual_mpesa' : 'cash', ...($manual ? ['mpesa_code' => 'SALECLAIM123', 'mpesa_amount' => '10.00', 'expected_receiving_number' => '0712345678'] : ['cash_tendered' => '10.00']), 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.00']]]);
        $service = app(SaleVoidService::class);
        $void = $service->void($this->employee, $sale, 'Canceled sale');
        if ($review) {
            $service->decideStock($this->owner, $void, 'not_restocked', 'Goods accounted for; not resellable');
        }

        return $void->refresh();
    }

    private function url(SaleVoid $void): string
    {
        return '/owner/sale-voids/'.$void->id.'/resolve';
    }

    /** @param array<string, mixed> $data */
    private function resolve(SaleVoid $void, array $data): void
    {
        $this->post($this->url($void), $data)->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(FinancialReportService::class)->build($this->owner, '2026-11-23', '2026-11-23');
    }

    private function failAudit(): void
    {
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
    }
}
