<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\FinancialReportService;
use App\Services\InstalmentService;
use App\Services\InventoryService;
use App\Services\SaleApprovalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(9, 0));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->employee = User::factory()->create();
        ShopSettings::findOrFail(1)->update(['shop_name' => 'Test shop', 'mpesa_number' => '0712345678']);
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $this->product = Product::create(['sku' => 'T1', 'name' => 'Tool', 'name_key' => 'tool', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $this->customer = Customer::forceCreate(['name' => 'Test customer', 'name_key' => 'test customer', 'contact' => '0700000001', 'contact_key' => '0700000001', 'created_by' => $this->owner->id, 'credit_limit_minor' => 10000]);
        $inventory = app(InventoryService::class);
        $batch = $inventory->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Opening fixture', 'rows' => [['sku' => 'T1', 'unit' => 'piece', 'quantity' => '3', 'unit_cost' => '3.01']]]);
        $inventory->post($this->owner, $batch, 'Reviewed');
        $inventory->completeOpening($this->owner, '0.00', 'Sign off', '2026-11-21');
        $this->actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_void_preserves_money_and_history_without_implicitly_restocking(): void
    {
        $sale = $this->sale();
        $this->void($sale);
        $this->void($sale);
        $this->assertSame('voided', $sale->refresh()->getAttribute('status'));
        $this->assertSame('2.000', $this->product->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('sale_voids', 1);
        $this->assertDatabaseHas('sale_voids', ['sale_id' => $sale->id, 'reversed_total_minor' => 1000, 'reversed_cost_minor' => 301]);
        $this->assertDatabaseHas('payments', ['sale_id' => $sale->id, 'amount_minor' => 1000, 'status' => 'verified']);
        $this->assertDatabaseCount('cash_outflows', 0);
        $this->get('/sales/'.$sale->id)->assertInertia(fn (Assert $page) => $page->where('sale.settlement.state', 'voided')->where('sale.settlement.confirmed', '10.00')->where('sale.settlement.balance_due', '0.00')->where('sale.void.stock_status', 'pending'));
        $this->get('/sales/'.$sale->id.'/receipt')->assertOk()->assertSee('VOIDED')->assertSee('No refund')->assertDontSee('Paid in full');
        $this->assertSame(1, DB::table('audit_events')->where('event', 'sale.voided')->count());
        $report = app(FinancialReportService::class)->build($this->owner, '2026-11-22', '2026-11-22');
        $this->assertSame('0.00', $report['summary']['net_sales']);
        $this->assertSame('10.00', $report['summary']['confirmed_collections']);
        $this->assertSame('10.00', $report['current']['void_confirmed_funds_held']);
    }

    public function test_credit_void_cancels_only_remaining_debt_and_keeps_prior_allocations(): void
    {
        $sale = $this->creditSale();
        app(InstalmentService::class)->collect($this->employee, ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => '2.00', 'method' => 'cash']);
        $this->void($sale);
        $void = SaleVoid::sole();
        $this->assertSame(600, $void->confirmed_minor);
        $this->assertDatabaseHas('sale_voids', ['id' => $void->id, 'canceled_debt_minor' => 400]);
        $this->assertSame(0, Receivable::sole()->remaining_amount_minor);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseCount('payments', 2);
        $this->actingAs($this->owner)->get('/owner/customers/'.$this->customer->id.'/statement')->assertInertia(fn (Assert $page) => $page->where('receivables.0.void_canceled_amount', '4.00')->has('allocations', 2));
    }

    public function test_pending_void_claim_can_be_verified_once_without_settling_canceled_debt(): void
    {
        $sale = $this->sale(true);
        $payment = Payment::sole();
        $this->void($sale);
        $this->assertSame(0, Receivable::sole()->remaining_amount_minor);
        $this->assertSame(0, SaleVoid::sole()->confirmed_minor);
        $this->decidePayment($payment, 'verified');
        $this->decidePayment($payment, 'verified');
        $this->assertSame(1000, SaleVoid::sole()->confirmed_minor);
        $this->assertSame(0, Receivable::sole()->remaining_amount_minor);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('payment_verifications', 1);
        $this->get('/owner/sale-voids')->assertInertia(fn (Assert $page) => $page->where('voids.data.0.confirmed_funds', '10.00')->where('voids.data.0.pending_claims', '0.00')->where('voids.data.0.funds_status', 'needs_review'));
    }

    public function test_disputed_void_claim_does_not_create_refund_liability(): void
    {
        $sale = $this->sale(true);
        $payment = Payment::sole();
        $this->void($sale);
        $this->decidePayment($payment, 'disputed');
        $this->assertSame(0, SaleVoid::sole()->confirmed_minor);
        $this->assertDatabaseHas('sale_voids', ['funds_status' => 'not_required']);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_owner_restock_restores_original_cost_once_after_a_new_delivery(): void
    {
        $sale = $this->sale();
        $service = app(InventoryService::class);
        $batch = $service->draft($this->owner, ['kind' => 'stock_receipt', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-22', 'source_note' => 'New delivery', 'supplier_name' => 'Supplier', 'delivery_reference' => 'DEL-1', 'payment_method' => 'cash', 'amount_paid' => '9.00', 'rows' => [['sku' => 'T1', 'unit' => 'piece', 'quantity' => '1', 'unit_cost' => '9.00']]]);
        $service->post($this->owner, $batch, 'Reviewed');
        $this->void($sale);
        $void = SaleVoid::sole();
        $this->stock($void, 'restocked');
        $this->stock($void, 'restocked');
        $this->assertSame('4.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(1803, $this->product->inventory_value_minor);
        $this->assertSame(1, DB::table('stock_movements')->where('kind', 'sale_void')->count());
        $this->assertDatabaseHas('stock_movements', ['kind' => 'sale_void', 'quantity_delta' => 1, 'value_delta_minor' => 301]);
        $this->get('/owner/inventory/ledger')->assertInertia(fn (Assert $page) => $page->where('movements.data.0.sale_id', $sale->id)->where('movements.data.0.label', 'Tool'));
    }

    public function test_not_resellable_review_never_increases_stock_and_is_final(): void
    {
        $this->void($this->sale());
        $void = SaleVoid::sole();
        $this->stock($void, 'not_restocked');
        $this->actingAs($this->owner)->post('/owner/sale-voids/'.$void->id.'/stock', $this->stockData('restocked'))->assertSessionHasErrors('decision');
        $this->assertSame('2.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(0, DB::table('stock_movements')->where('kind', 'sale_void')->count());
    }

    public function test_unknown_original_cost_never_gets_replaced_by_current_catalogue_cost(): void
    {
        $this->product->forceFill(['inventory_value_minor' => null, 'average_cost_minor' => null])->save();
        $this->void($this->sale());
        $this->product->refresh()->forceFill(['purchase_cost_minor' => 900])->save();
        $this->stock(SaleVoid::sole(), 'restocked');
        $this->assertNull($this->product->refresh()->inventory_value_minor);
        $this->assertDatabaseHas('stock_movements', ['kind' => 'sale_void', 'value_delta_minor' => null]);
    }

    public function test_staff_cannot_void_other_sales_or_approve_stock_or_view_owner_queue(): void
    {
        $sale = $this->sale();
        $this->actingAs(User::factory()->create())->post('/sales/'.$sale->id.'/void', $this->voidData())->assertForbidden();
        $this->actingAs($this->employee);
        $this->void($sale);
        $this->post('/owner/sale-voids/'.SaleVoid::sole()->id.'/stock', $this->stockData('restocked'))->assertForbidden();
        $this->get('/owner/sale-voids')->assertForbidden();
    }

    public function test_missing_reason_or_confirmation_does_not_void_or_restock(): void
    {
        $sale = $this->sale();
        $this->post('/sales/'.$sale->id.'/void', ['confirm' => true])->assertSessionHasErrors('reason');
        $this->post('/sales/'.$sale->id.'/void', ['reason' => 'Mistake'])->assertSessionHasErrors('confirm');
        $this->assertDatabaseCount('sale_voids', 0);
        $this->void($sale);
        $this->actingAs($this->owner)->post('/owner/sale-voids/'.SaleVoid::sole()->id.'/stock', ['decision' => 'restocked', 'reason' => 'Checked'])->assertSessionHasErrors('confirm');
        $this->assertSame('2.000', $this->product->refresh()->quantity_on_hand);
    }

    public function test_audit_failure_rolls_back_void_and_credit_cancellation(): void
    {
        $sale = $this->creditSale();
        $this->failAudit();
        try {
            $this->post('/sales/'.$sale->id.'/void', $this->voidData());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('posted', $sale->refresh()->getAttribute('status'));
        $this->assertSame(600, Receivable::sole()->remaining_amount_minor);
        $this->assertDatabaseCount('sale_voids', 0);
    }

    public function test_audit_failure_rolls_back_restock_values_and_disposition(): void
    {
        $this->void($this->sale());
        $void = SaleVoid::sole();
        $this->actingAs($this->owner);
        $this->failAudit();
        try {
            $this->post('/owner/sale-voids/'.$void->id.'/stock', $this->stockData('restocked'));
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('2.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame(602, $this->product->inventory_value_minor);
        $this->assertSame('pending', $void->refresh()->stock_status);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_migration_roundtrip_keeps_sale_and_stock_and_refuses_void_history(): void
    {
        $sale = $this->sale();
        $payoutMigration = require database_path('migrations/2026_11_23_000011_create_customer_credit_payouts.php');
        $payoutMigration->down();
        $resolutionMigration = require database_path('migrations/2026_11_23_000010_create_void_resolutions.php');
        $resolutionMigration->down();
        $migration = require database_path('migrations/2026_11_22_000009_create_sale_voids.php');
        $migration->down();
        $migration->up();
        $resolutionMigration->up();
        $payoutMigration->up();
        $this->assertSame('2.000', $this->product->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('payments', 1);
        $this->void($sale);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_restock_movement_cannot_have_two_sources(): void
    {
        $this->void($this->sale());
        $this->stock(SaleVoid::sole(), 'restocked');
        $movement = DB::table('stock_movements')->where('kind', 'sale_void')->sole();
        $saleMovement = DB::table('stock_movements')->where('kind', 'sale')->sole();
        $this->expectException(QueryException::class);
        DB::table('stock_movements')->where('id', $movement->id)->update(['sale_item_id' => $saleMovement->sale_item_id]);
    }

    public function test_full_credit_void_has_no_fake_confirmed_money_or_refund_due(): void
    {
        $sale = $this->creditSale('10.00');
        $this->void($sale);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('sale_voids', ['sale_id' => $sale->id, 'confirmed_minor' => 0, 'canceled_debt_minor' => 1000, 'funds_status' => 'not_required']);
        $this->get('/sales/'.$sale->id)->assertInertia(fn (Assert $page) => $page->where('sale.settlement.confirmed', '0.00')->where('sale.settlement.state', 'voided'));
    }

    public function test_pending_instalment_cannot_allocate_to_canceled_sale_debt(): void
    {
        $sale = $this->creditSale();
        $payment = app(InstalmentService::class)->collect($this->employee, ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => '6.00', 'method' => 'manual_mpesa', 'mpesa_code' => 'PENDING123', 'expected_receiving_number' => '0712345678']);
        $this->void($sale);
        $this->actingAs($this->owner)->post('/owner/payments/'.$payment->id.'/decision', ['decision' => 'verified', 'reason' => 'Owner checked message', 'confirm' => true])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame(0, Receivable::sole()->remaining_amount_minor);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_void_claim_verification_audit_failure_rolls_back_held_money(): void
    {
        $sale = $this->sale(true);
        $this->void($sale);
        $payment = Payment::sole();
        $this->actingAs($this->owner);
        $this->failAudit();
        try {
            $this->post('/owner/payments/'.$payment->id.'/decision', ['decision' => 'verified', 'reason' => 'Owner checked message', 'confirm' => true]);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame(0, SaleVoid::sole()->confirmed_minor);
        $this->assertDatabaseCount('payment_verifications', 0);
    }

    private function sale(bool $manual = false): Sale
    {
        return app(CheckoutService::class)->checkout($this->employee, ['submission_key' => (string) Str::uuid(), 'payment_method' => $manual ? 'manual_mpesa' : 'cash', ...($manual ? ['mpesa_code' => 'VOIDTEST123', 'mpesa_amount' => '10.00', 'expected_receiving_number' => '0712345678'] : ['cash_tendered' => '10.00']), 'items' => $this->items()]);
    }

    private function creditSale(string $credit = '6.00'): Sale
    {
        $service = app(SaleApprovalService::class);
        $approval = $service->request($this->employee, ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'payment_method' => 'cash', 'discount_amount' => '0.00', 'credit_amount' => $credit, 'due_on' => '2026-11-25', 'reason' => 'Terms', 'items' => $this->items()]);
        $service->decide($this->owner, $approval, 'approved', 'Reviewed', 30);

        return app(CheckoutService::class)->checkout($this->employee, ['submission_key' => $approval->checkout_key, 'approval_id' => $approval->id, 'customer_id' => $this->customer->id, 'payment_method' => 'cash', 'cash_tendered' => $credit === '10.00' ? '0.00' : '4.00', 'items' => $this->items()]);
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        return [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.00']];
    }

    /** @return array<string, mixed> */
    private function voidData(): array
    {
        return ['reason' => 'Customer canceled; goods need owner review', 'confirm' => true];
    }

    private function void(Sale $sale): void
    {
        $this->post('/sales/'.$sale->id.'/void', $this->voidData())->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function stockData(string $decision): array
    {
        return ['decision' => $decision, 'reason' => 'Owner physically checked every original unit', 'confirm' => true];
    }

    private function stock(SaleVoid $void, string $decision): void
    {
        $this->actingAs($this->owner)->post('/owner/sale-voids/'.$void->id.'/stock', $this->stockData($decision))->assertSessionHasNoErrors();
    }

    private function decidePayment(Payment $payment, string $decision): void
    {
        $this->actingAs($this->owner)->post('/owner/payments/'.$payment->id.'/decision', ['decision' => $decision, 'reason' => 'Owner checked original message', 'confirm' => true])->assertSessionHasNoErrors();
    }

    private function failAudit(): void
    {
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
    }
}
