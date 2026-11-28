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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManualMpesaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-22T09:00:00Z'));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->employee = User::factory()->create();
        ShopSettings::query()->findOrFail(1)->update(['shop_name' => 'Test hardware', 'mpesa_number' => '0712345678']);
        $category = Category::create(['name' => 'Test tools', 'name_key' => 'test tools']);
        $this->product = Product::create(['sku' => 'TEST-1', 'name' => 'Test product', 'name_key' => 'test product', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $service = app(InventoryService::class);
        $batch = $service->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Test opening count', 'rows' => [['sku' => $this->product->sku, 'unit' => 'piece', 'quantity' => '20', 'unit_cost' => '5.00']]]);
        $service->post($this->owner, $batch, 'Test owner review');
        $service->completeOpening($this->owner, '0.00', 'Test sign-off', '2026-11-21');
        $this->actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_pending_checkout_records_stock_and_full_balance_without_confirmed_money(): void
    {
        $payment = $this->pending();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('0712345678', $payment->receiving_number);
        $this->assertSame($this->employee->id, $payment->handled_by);
        $this->assertSame('19.000', $this->product->refresh()->quantity_on_hand);
        $this->assertDatabaseHas('receivables', ['sale_id' => $payment->sale_id, 'customer_id' => null, 'origin' => 'payment_verification', 'original_amount_minor' => 1000, 'remaining_amount_minor' => 1000]);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->get('/sales/'.$payment->sale_id)->assertInertia(fn (Assert $page) => $page->where('sale.checkout_method', 'manual_mpesa')->where('sale.settlement.confirmed', '0.00')->where('sale.settlement.pending', '10.00')->where('sale.settlement.balance_due', '10.00')->where('sale.settlement.state', 'pending_verification')->missing('sale.cost_total'));
        $this->get('/sales/'.$payment->sale_id.'/receipt')->assertOk()->assertSee('pending owner verification')->assertSee('0712345678')->assertDontSee('Paid in full');
        $this->get('/payments')->assertInertia(fn (Assert $page) => $page->where('totals.confirmed_total', '0.00')->where('totals.mpesa_pending', '10.00')->where('totals.mpesa_recorded', '10.00'));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('paymentAlerts.pending', 1));
    }

    public function test_owner_verification_settles_once_and_preserves_collector_and_entry_day(): void
    {
        $payment = $this->pending();
        $this->travelTo(CarbonImmutable::parse('2026-11-24T10:00:00Z'));
        $this->decide($payment, 'verified');
        $this->decide($payment, 'verified');
        $this->assertSame('verified', $payment->refresh()->status);
        $this->assertSame($this->employee->id, $payment->handled_by);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'business_date' => '2026-11-22']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $payment->sale_id, 'remaining_amount_minor' => 0]);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id, 'amount_minor' => 1000, 'actor_id' => $this->owner->id]);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('payment_verifications', 1);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'payment.verified')->count());
        $this->get('/sales/'.$payment->sale_id)->assertInertia(fn (Assert $page) => $page->where('sale.settlement.balance_due', '0.00')->where('sale.settlement.confirmed', '10.00')->where('sale.settlement.state', 'paid'));
        $this->get('/payments?from=2026-11-22&to=2026-11-22')->assertInertia(fn (Assert $page) => $page->where('totals.mpesa_verified', '10.00')->where('totals.mpesa_pending', '0.00'));
        $this->get('/sales/'.$payment->sale_id.'/receipt')->assertSee('Paid in full')->assertDontSee('M-Pesa pending owner verification');
        $this->assertSame('19.000', $this->product->refresh()->quantity_on_hand);
    }

    public function test_dispute_preserves_sale_stock_and_unpaid_balance_and_alerts_employee(): void
    {
        $payment = $this->pending();
        $this->decide($payment, 'disputed');
        $this->decide($payment, 'disputed');
        $this->assertDatabaseCount('payment_verifications', 1);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseHas('receivables', ['sale_id' => $payment->sale_id, 'remaining_amount_minor' => 1000]);
        $this->assertDatabaseHas('sales', ['id' => $payment->sale_id, 'status' => 'posted', 'total_minor' => 1000]);
        $this->assertSame('19.000', $this->product->refresh()->quantity_on_hand);
        $this->actingAs($this->employee)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('paymentAlerts.disputed', 1)->where('paymentAlerts.pending', 0));
        $this->get('/payments?status=disputed')->assertInertia(fn (Assert $page) => $page->has('payments.data', 1)->where('totals.confirmed_total', '0.00')->where('totals.mpesa_disputed', '10.00'));
        $this->get('/payments/'.$payment->id)->assertInertia(fn (Assert $page) => $page->where('payment.status', 'disputed')->where('decision.reason', 'Owner checked the original message and statement.'));
        $this->get('/sales/'.$payment->sale_id.'/receipt')->assertSee('Payment disputed')->assertDontSee('Paid in full');
    }

    public function test_conflicting_owner_decision_is_rejected_without_rewriting_history(): void
    {
        $payment = $this->pending();
        $this->decide($payment, 'verified');
        $this->post('/owner/payments/'.$payment->id.'/decision', $this->decisionData('disputed'))->assertSessionHasErrors('decision');
        $this->assertSame('verified', $payment->refresh()->status);
        $this->assertDatabaseCount('payment_verifications', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('receivables', ['sale_id' => $payment->sale_id, 'remaining_amount_minor' => 0]);
    }

    public function test_employee_cannot_decide_payments_or_read_someone_elses_record(): void
    {
        $payment = $this->pending();
        $this->post('/owner/payments/'.$payment->id.'/decision', $this->decisionData('verified'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/payments/'.$payment->id)->assertForbidden();
        $this->get('/payments')->assertInertia(fn (Assert $page) => $page->has('payments.data', 0)->where('totals.mpesa_recorded', '0.00'));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('paymentAlerts.pending', 0));
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertDatabaseCount('payment_verifications', 0);
    }

    public function test_receiving_number_changes_preserve_old_entries_and_reject_stale_checkout(): void
    {
        $old = $this->pending();
        $settings = ShopSettings::query()->findOrFail(1);
        $this->actingAs($this->owner)->patch('/owner/shop-settings', ['shop_name' => 'Test hardware', 'mpesa_number' => '+254712345679', 'mpesa_number_confirmation' => '+254712345679', 'version' => $settings->version, 'reason' => 'Owner changed destination'])->assertSessionHasNoErrors();
        $this->assertSame('0712345678', $old->refresh()->receiving_number);
        $this->actingAs($this->employee)->post('/sales', $this->data('NEWCODE123'))->assertSessionHasErrors('expected_receiving_number');
        $new = $this->pending([...$this->data('NEWCODE123'), 'expected_receiving_number' => '+254712345679']);
        $this->assertSame('+254712345679', $new->receiving_number);
        $this->decide($old, 'verified');
        $this->get('/payments/'.$old->id)->assertInertia(fn (Assert $page) => $page->where('payment.receiving_number', '0712345678'));
        $this->assertDatabaseHas('audit_events', ['event' => 'shop.settings_changed', 'actor_id' => $this->owner->id]);
        $this->assertSame('pending', $new->refresh()->status);
    }

    public function test_code_normalization_uniqueness_and_retry_do_not_duplicate_sales(): void
    {
        $data = $this->data(' testabc123 ');
        $payment = $this->pending($data);
        $this->assertSame('TESTABC123', $payment->mpesa_code);
        $this->post('/sales', $data)->assertSessionHasNoErrors()->assertRedirect('/sales/'.$payment->sale_id);
        $this->post('/sales', $this->data('TESTABC123'))->assertSessionHasErrors('mpesa_code');
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('receivables', 1);
        $this->assertSame('19.000', $this->product->refresh()->quantity_on_hand);
    }

    public function test_missing_code_wrong_amount_and_forged_verification_are_rejected(): void
    {
        $this->post('/sales', [...$this->data(), 'mpesa_code' => ''])->assertSessionHasErrors('mpesa_code');
        $this->post('/sales', [...$this->data(), 'mpesa_amount' => '9.99'])->assertSessionHasErrors('mpesa_amount');
        $this->post('/sales', [...$this->data(), 'mpesa_amount' => '10.01'])->assertSessionHasErrors('mpesa_amount');
        $this->post('/sales', [...$this->data(), 'status' => 'verified', 'verified_by' => $this->owner->id, 'receiving_number' => '0700000000'])->assertSessionHasErrors(['status', 'verified_by', 'receiving_number']);
        $this->post('/sales', [...$this->data(), 'cash_tendered' => '10'])->assertSessionHasErrors('cash_tendered');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_missing_receiving_number_disables_new_mpesa_without_affecting_cash(): void
    {
        ShopSettings::query()->findOrFail(1)->update(['mpesa_number' => null]);
        $this->get('/sales/create')->assertInertia(fn (Assert $page) => $page->where('mpesaNumber', null));
        $this->post('/sales', $this->data())->assertSessionHasErrors('expected_receiving_number');
        $this->cashSale();
        $this->assertDatabaseCount('receivables', 0);
    }

    public function test_current_status_totals_and_queue_preserve_cash_and_employee_scoping(): void
    {
        $this->cashSale();
        $first = $this->pending($this->data('FIRSTCODE1'));
        $second = $this->pending($this->data('SECONDCODE'));
        $this->decide($first, 'verified');
        $this->decide($second, 'disputed');
        $this->get('/payments?status=disputed')->assertInertia(fn (Assert $page) => $page->has('payments.data', 1)->where('totals.confirmed_total', '20.00')->where('totals.cash_confirmed', '10.00')->where('totals.mpesa_recorded', '20.00')->where('totals.mpesa_verified', '10.00')->where('totals.mpesa_disputed', '10.00')->where('totals.mpesa_pending', '0.00'));
        $this->actingAs(User::factory()->create())->get('/payments')->assertInertia(fn (Assert $page) => $page->where('totals.confirmed_total', '0.00')->where('totals.mpesa_recorded', '0.00'));
    }

    public function test_verified_checkout_settles_its_sale_and_preserves_opening_customer_debt(): void
    {
        $customer = new Customer(['name' => 'Test customer', 'name_key' => 'test customer', 'contact' => 'Test contact', 'contact_key' => 'testcontact']);
        $customer->setAttribute('created_by', $this->owner->id);
        $customer->save();
        DB::table('receivables')->insert(['customer_id' => $customer->id, 'opening_customer_id' => $customer->id, 'origin' => 'opening_balance', 'original_amount_minor' => 3000, 'remaining_amount_minor' => 3000, 'originated_on' => '2026-11-21', 'due_on' => '2026-11-20', 'source_note' => 'Test paper debt', 'actor_id' => $this->owner->id, 'created_at' => now()]);
        $payment = $this->pending([...$this->data(), 'customer_id' => $customer->id]);
        $this->actingAs($this->owner)->get('/owner/customers/'.$customer->id.'/statement')->assertInertia(fn (Assert $page) => $page->has('receivables', 2)->where('receivables.1.remaining_amount', '10.00')->where('receivables.1.payment_status', 'pending')->has('allocations', 0));
        $this->decide($payment, 'verified');
        $this->assertDatabaseHas('receivables', ['opening_customer_id' => $customer->id, 'remaining_amount_minor' => 3000]);
        $this->assertDatabaseHas('receivables', ['sale_id' => $payment->sale_id, 'remaining_amount_minor' => 0]);
        $this->get('/owner/customers/'.$customer->id.'/statement')->assertInertia(fn (Assert $page) => $page->has('allocations', 1)->where('receivables.1.payment_status', 'verified'));
        $this->assertSame(0, $customer->refresh()->credit_limit_minor);
    }

    public function test_owner_cannot_use_manual_verification_to_rewrite_a_cash_payment(): void
    {
        $this->cashSale();
        $payment = Payment::query()->sole();
        $this->actingAs($this->owner)->post('/owner/payments/'.$payment->id.'/decision', $this->decisionData('disputed'))->assertForbidden();
        $this->assertSame('verified', $payment->refresh()->status);
        $this->assertDatabaseCount('payment_verifications', 0);
    }

    public function test_decision_requires_confirmation_and_review_reason(): void
    {
        $payment = $this->pending();
        $this->actingAs($this->owner)->post('/owner/payments/'.$payment->id.'/decision', ['decision' => 'verified'])->assertSessionHasErrors(['confirm', 'reason']);
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_audit_failure_rolls_back_status_balance_allocation_and_decision(): void
    {
        $payment = $this->pending();
        $this->actingAs($this->owner);
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/owner/payments/'.$payment->id.'/decision', $this->decisionData('verified'));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertDatabaseHas('receivables', ['sale_id' => $payment->sale_id, 'remaining_amount_minor' => 1000]);
        $this->assertDatabaseCount('payment_verifications', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_migration_rolls_back_with_cash_only_but_refuses_manual_history(): void
    {
        $this->cashSale();
        $migration = require database_path('migrations/2026_11_22_000005_add_manual_mpesa_verification.php');
        $migration->down();
        $this->assertDatabaseCount('payments', 1);
        $migration->up();
        $this->assertDatabaseHas('sales', ['checkout_method' => 'cash']);
        $this->pending();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment history exists');
        $migration->down();
    }

    public function test_receiving_number_replacement_needs_matching_confirmation(): void
    {
        $settings = ShopSettings::query()->findOrFail(1);
        $this->actingAs($this->owner)->patch('/owner/shop-settings', ['shop_name' => 'Test hardware', 'mpesa_number' => '0712345679', 'mpesa_number_confirmation' => '0712345678', 'version' => $settings->version, 'reason' => 'Changed number'])->assertSessionHasErrors('mpesa_number');
        $this->assertSame('0712345678', $settings->refresh()->mpesa_number);
    }

    public function test_incoming_and_outgoing_mpesa_codes_cannot_be_reused_in_either_direction(): void
    {
        $service = app(InventoryService::class);
        $delivery = ['kind' => 'stock_receipt', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-22', 'source_note' => 'Test paid delivery', 'supplier_name' => 'Test supplier', 'delivery_reference' => 'DELIVERY-1', 'payment_method' => 'mpesa', 'payment_reference' => 'OUTGOING12', 'amount_paid' => '5.00', 'rows' => [['sku' => $this->product->sku, 'unit' => 'piece', 'quantity' => '1', 'unit_cost' => '5.00']]];
        $batch = $service->draft($this->owner, $delivery);
        $service->post($this->owner, $batch, 'Owner paid supplier.');
        $this->post('/sales', $this->data('OUTGOING12'))->assertSessionHasErrors('mpesa_code');
        $this->assertDatabaseCount('sales', 0);
        $this->pending($this->data('INCOMING12'));
        $second = $service->draft($this->owner, [...$delivery, 'submission_key' => (string) Str::uuid(), 'delivery_reference' => 'DELIVERY-2', 'payment_reference' => 'INCOMING12']);
        $this->actingAs($this->owner)->post('/owner/inventory/'.$second->id.'/post', ['confirm' => true, 'reason' => 'Test duplicate code'])->assertSessionHasErrors('batch');
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertSame('20.000', $this->product->refresh()->quantity_on_hand);
        $this->assertSame('draft', $second->refresh()->status);
    }

    public function test_verification_cannot_overallocate_if_the_balance_changed(): void
    {
        $payment = $this->pending();
        DB::table('receivables')->where('sale_id', $payment->sale_id)->update(['remaining_amount_minor' => 500]);
        $this->actingAs($this->owner)->post('/owner/payments/'.$payment->id.'/decision', $this->decisionData('verified'))->assertSessionHasErrors('decision');
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('payment_verifications', 0);
    }

    /** @return array<string, mixed> */
    private function data(string $code = 'TESTABC123'): array
    {
        return ['submission_key' => (string) Str::uuid(), 'payment_method' => 'manual_mpesa', 'mpesa_code' => $code, 'mpesa_amount' => '10.00', 'expected_receiving_number' => '0712345678', 'payer_reference' => 'Test payer', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.00']]];
    }

    /** @param array<string, mixed>|null $data */
    private function pending(?array $data = null): Payment
    {
        $data ??= $this->data();
        $response = $this->post('/sales', $data)->assertSessionHasNoErrors();
        $sale = Sale::query()->where('submission_key', $data['submission_key'])->firstOrFail();
        $response->assertRedirect('/sales/'.$sale->id);

        return Payment::query()->where('sale_id', $sale->id)->sole();
    }

    private function cashSale(): void
    {
        $this->post('/sales', ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => '20.00', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.00']]])->assertSessionHasNoErrors()->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function decisionData(string $decision): array
    {
        return ['decision' => $decision, 'confirm' => true, 'reason' => 'Owner checked the original message and statement.'];
    }

    private function decide(Payment $payment, string $decision): void
    {
        $this->actingAs($this->owner)->post('/owner/payments/'.$payment->id.'/decision', $this->decisionData($decision))->assertSessionHasNoErrors()->assertRedirect('/payments/'.$payment->id);
    }
}
