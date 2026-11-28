<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleRequest;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApprovalsAndInstalmentsTest extends TestCase
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
        ShopSettings::findOrFail(1)->update(['shop_name' => 'Test hardware', 'mpesa_number' => '0712345678']);
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $this->product = Product::create(['sku' => 'T1', 'name' => 'Hammer', 'name_key' => 'hammer', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $this->customer = Customer::forceCreate(['name' => 'Customer One', 'name_key' => 'customer one', 'contact' => '0700000001', 'contact_key' => '0700000001', 'created_by' => $this->owner->id]);
        $this->customer->forceFill(['credit_limit_minor' => 10000])->save();
        $inventory = app(InventoryService::class);
        $batch = $inventory->draft($this->owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-21', 'source_note' => 'Test opening', 'rows' => [['sku' => 'T1', 'unit' => 'piece', 'quantity' => '20', 'unit_cost' => '5.00']]]);
        $inventory->post($this->owner, $batch, 'Checked');
        $inventory->completeOpening($this->owner, '0.00', 'Sign off', '2026-11-21');
        $this->actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_request_is_inert_idempotent_and_private_with_owner_only_decision(): void
    {
        $data = $this->proposal();
        $this->post('/sale-requests', $data)->assertSessionHasNoErrors();
        $this->post('/sale-requests', $data)->assertSessionHasNoErrors();
        $request = SaleRequest::sole();
        $this->assertDatabaseCount('sale_requests', 1);
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('20.000', $this->product->refresh()->quantity_on_hand);
        $this->get('/sale-requests/'.$request->id)->assertInertia(fn (Assert $page) => $page->where('creditReview', null)->where('approval.items.0.name', 'Hammer'));
        $this->post('/owner/sale-requests/'.$request->id.'/decision', $this->decision())->assertForbidden();
        $other = User::factory()->create();
        $this->actingAs($other)->get('/sale-requests/'.$request->id)->assertForbidden();
        $this->get('/sale-requests')->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->actingAs($this->owner)->get('/sale-requests/'.$request->id)->assertInertia(fn (Assert $page) => $page->where('creditReview.limit', '100.00')->where('creditReview.outstanding', '0.00'));
        $this->actingAs($this->employee)->post('/sale-requests', [...$data, 'discount_amount' => '2.00'])->assertSessionHasErrors('approval');
    }

    public function test_approved_cash_discount_credit_and_retries_reconcile_exactly(): void
    {
        $request = $this->approved();
        $data = $this->checkoutData($request);
        $this->post('/sales', $data)->assertSessionHasNoErrors();
        $this->post('/sales', $data)->assertSessionHasNoErrors();
        $sale = Sale::sole();
        $this->assertSame(1000, $sale->gross_minor);
        $this->assertSame(100, $sale->discount_minor);
        $this->assertSame(900, $sale->total_minor);
        $this->assertSame(500, $sale->cost_total_minor);
        $this->assertSame(500, $sale->change_minor);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'gross_minor' => 1000, 'discount_minor' => 100, 'total_minor' => 900]);
        $this->assertDatabaseHas('payments', ['sale_id' => $sale->id, 'amount_minor' => 500, 'status' => 'verified']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale->id, 'origin' => 'credit_sale', 'original_amount_minor' => 900, 'remaining_amount_minor' => 400]);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame('consumed', $request->refresh()->status);
        $this->assertSame('19.000', $this->product->refresh()->quantity_on_hand);
        $this->get('/sales/'.$sale->id)->assertInertia(fn (Assert $page) => $page->where('sale.settlement.state', 'credit_due')->where('sale.settlement.confirmed', '5.00')->where('sale.settlement.balance_due', '4.00'));
        $this->get('/sales/'.$sale->id.'/receipt')->assertOk()->assertSee('Approved discount')->assertSee('2026-11-30');
        $this->post('/sales', [...$data, 'submission_key' => (string) Str::uuid()])->assertSessionHasErrors('approval_id');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_expired_changed_cart_and_changed_customer_cannot_post(): void
    {
        $request = $this->approved();
        $data = $this->checkoutData($request);
        $changed = $data;
        $changed['items'][0]['quantity'] = '2';
        $this->post('/sales', $changed)->assertSessionHasErrors('approval_id');
        $this->customer->update(['name' => 'Changed name']);
        $this->post('/sales', $data)->assertSessionHasErrors('approval_id');
        $this->customer->update(['name' => 'Customer One']);
        $this->travel(31)->minutes();
        $this->post('/sales', $data)->assertSessionHasErrors('approval_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('20.000', $this->product->refresh()->quantity_on_hand);
    }

    public function test_limit_is_rechecked_on_request_owner_decision_and_checkout(): void
    {
        $this->customer->forceFill(['credit_limit_minor' => 399])->save();
        $this->post('/sale-requests', $this->proposal())->assertSessionHasErrors('approval');
        $this->customer->forceFill(['credit_limit_minor' => 400])->save();
        $this->post('/sale-requests', $this->proposal())->assertSessionHasNoErrors();
        $request = SaleRequest::sole();
        $this->customer->forceFill(['credit_limit_minor' => 399])->save();
        $this->actingAs($this->owner)->post('/owner/sale-requests/'.$request->id.'/decision', $this->decision())->assertSessionHasErrors('approval');
        $this->assertSame('pending', $request->refresh()->status);
        $this->customer->forceFill(['credit_limit_minor' => 400])->save();
        $this->post('/owner/sale-requests/'.$request->id.'/decision', $this->decision())->assertSessionHasNoErrors();
        $this->customer->forceFill(['credit_limit_minor' => 399])->save();
        $this->actingAs($this->employee)->post('/sales', $this->checkoutData($request))->assertSessionHasErrors('approval');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_full_credit_has_no_fake_cash_collection_and_repayment_settles_sale(): void
    {
        $request = $this->approved(['discount_amount' => '0.00', 'credit_amount' => '10.00']);
        $this->post('/sales', [...$this->checkoutData($request), 'cash_tendered' => '0.00'])->assertSessionHasNoErrors();
        $sale = Sale::sole();
        $this->assertDatabaseCount('payments', 0);
        $this->post('/payments/collect', $this->collection('10.00'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payments', ['purpose' => 'instalment', 'sale_id' => null, 'amount_minor' => 1000]);
        $this->get('/sales/'.$sale->id)->assertInertia(fn (Assert $page) => $page->where('sale.settlement.state', 'paid')->where('sale.settlement.confirmed', '10.00'));
    }

    public function test_pending_deposit_reserves_claim_and_does_not_hide_credit_exposure(): void
    {
        $this->customer->forceFill(['credit_limit_minor' => 899])->save();
        $this->post('/sale-requests', $this->proposal(['payment_method' => 'manual_mpesa']))->assertSessionHasErrors('approval');
        $this->customer->forceFill(['credit_limit_minor' => 900])->save();
        $request = $this->approved(['payment_method' => 'manual_mpesa']);
        $data = $this->checkoutData($request);
        unset($data['cash_tendered']);
        $this->post('/sales', [...$data, 'mpesa_amount' => '5.00', 'mpesa_code' => 'DEPOSIT123', 'expected_receiving_number' => '0712345678'])->assertSessionHasNoErrors();
        $deposit = Payment::sole();
        $this->assertSame(900, Receivable::sole()->remaining_amount_minor);
        $this->post('/payments/collect', $this->collection('4.01'))->assertSessionHasErrors('amount');
        $this->post('/payments/collect', $this->collection('4.00'))->assertSessionHasNoErrors();
        $this->assertSame(500, Receivable::sole()->remaining_amount_minor);
        $this->actingAs($this->owner)->post('/owner/payments/'.$deposit->id.'/decision', ['decision' => 'verified', 'reason' => 'Message checked', 'confirm' => true])->assertSessionHasNoErrors();
        $this->assertSame(0, Receivable::sole()->remaining_amount_minor);
        $this->assertDatabaseCount('payment_allocations', 2);
    }

    public function test_cash_instalment_allocates_oldest_first_and_retries_do_not_duplicate(): void
    {
        $old = $this->debt(600, '2026-11-01');
        $next = $this->debt(700, '2026-11-02');
        $data = $this->collection('8.00');
        $this->post('/payments/collect', $data)->assertSessionHasNoErrors();
        $this->post('/payments/collect', $data)->assertSessionHasNoErrors();
        $this->assertSame(0, $old->refresh()->remaining_amount_minor);
        $this->assertSame(500, $next->refresh()->remaining_amount_minor);
        $payment = Payment::sole();
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id, 'receivable_id' => $old->id, 'amount_minor' => 600]);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id, 'receivable_id' => $next->id, 'amount_minor' => 200]);
        $this->post('/payments/collect', [...$data, 'amount' => '1.00'])->assertSessionHasErrors('submission_key');
        $this->get('/payments/'.$payment->id.'/receipt')->assertOk()->assertSee('Customer One')->assertSee('8.00');
        $this->actingAs(User::factory()->create())->get('/payments/'.$payment->id.'/receipt')->assertForbidden();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_pending_instalment_reserves_balance_verifies_once_and_dispute_releases_it(): void
    {
        $debt = $this->debt(1000);
        $this->post('/payments/collect', $this->collection('8.00', 'manual_mpesa'))->assertSessionHasNoErrors();
        $payment = Payment::sole();
        $this->assertSame(1000, $debt->refresh()->remaining_amount_minor);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->post('/payments/collect', $this->collection('2.01'))->assertSessionHasErrors('amount');
        $decision = ['decision' => 'verified', 'reason' => 'Owner checked statement', 'confirm' => true];
        $this->post('/owner/payments/'.$payment->id.'/decision', $decision)->assertForbidden();
        $this->actingAs($this->owner)->post('/owner/payments/'.$payment->id.'/decision', $decision)->assertSessionHasNoErrors();
        $this->post('/owner/payments/'.$payment->id.'/decision', $decision)->assertSessionHasNoErrors();
        $this->assertSame(200, $debt->refresh()->remaining_amount_minor);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('payment_verifications', 1);
        $this->actingAs($this->employee)->post('/payments/collect', [...$this->collection('2.00', 'manual_mpesa'), 'mpesa_code' => 'SECOND123'])->assertSessionHasNoErrors();
        $second = Payment::latest('id')->firstOrFail();
        $this->actingAs($this->owner)->post('/owner/payments/'.$second->id.'/decision', [...$decision, 'decision' => 'disputed'])->assertSessionHasNoErrors();
        $this->assertSame(200, $debt->refresh()->remaining_amount_minor);
        $this->actingAs($this->employee)->post('/payments/collect', $this->collection('2.00'))->assertSessionHasNoErrors();
        $this->assertSame(0, $debt->refresh()->remaining_amount_minor);
    }

    public function test_receipt_preserves_customer_shop_and_original_number(): void
    {
        $this->debt(1000);
        $this->post('/payments/collect', $this->collection('5.00', 'manual_mpesa'))->assertSessionHasNoErrors();
        $payment = Payment::sole();
        ShopSettings::findOrFail(1)->update(['shop_name' => 'New shop', 'mpesa_number' => '0799999999']);
        $this->customer->update(['name' => 'New customer']);
        $this->get('/payments/'.$payment->id.'/receipt')->assertOk()->assertSee('Test hardware')->assertSee('Customer One')->assertSee('0712345678')->assertDontSee('0799999999');
        $this->post('/payments/collect', [...$this->collection('1.00', 'manual_mpesa'), 'mpesa_code' => 'NEWCODE123'])->assertSessionHasErrors('expected_receiving_number');
        $this->post('/payments/collect', [...$this->collection('1.00', 'manual_mpesa'), 'expected_receiving_number' => '0799999999'])->assertSessionHasErrors('mpesa_code');
    }

    public function test_migration_roundtrip_preserves_stock_and_refuses_with_request_history(): void
    {
        $migration = require database_path('migrations/2026_11_22_000006_create_approvals_and_instalments.php');
        $migration->down();
        $migration->up();
        $this->assertSame('20.000', $this->product->refresh()->quantity_on_hand);
        $this->post('/sale-requests', $this->proposal())->assertSessionHasNoErrors();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_audit_failure_rolls_back_collection_and_all_allocations(): void
    {
        $debt = $this->debt(1000);
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/payments/collect', $this->collection('5.00'));
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame(1000, $debt->refresh()->remaining_amount_minor);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_collecting_for_another_employees_sale_does_not_expose_their_details(): void
    {
        $request = $this->approved(['discount_amount' => '0.00', 'credit_amount' => '10.00']);
        $this->post('/sales', [...$this->checkoutData($request), 'cash_tendered' => '0.00'])->assertSessionHasNoErrors();
        $sale = Sale::sole();
        $collector = User::factory()->create();
        $this->actingAs($collector)->post('/payments/collect', $this->collection('1.00'))->assertSessionHasNoErrors();
        $payment = Payment::sole();
        $this->get('/payments/'.$payment->id)->assertInertia(fn (Assert $page) => $page->where('sale', null)->where('allocationNotes', null)->where('allocations.0.can_view_sale', false)->where('allocations.0.amount', '1.00')->missing('customer.credit_limit_minor')->missing('allocations.0.remaining_amount_minor'));
        $this->get('/sales/'.$sale->id)->assertForbidden();
        $this->get('/owner/customers/'.$this->customer->id.'/statement')->assertForbidden();
    }

    public function test_rejected_request_price_change_and_past_due_date_do_not_post(): void
    {
        $this->post('/sale-requests', $this->proposal(['due_on' => '2026-11-21']))->assertSessionHasErrors('due_on');
        $this->post('/sale-requests', $this->proposal())->assertSessionHasNoErrors();
        $request = SaleRequest::sole();
        $this->actingAs($this->owner)->post('/owner/sale-requests/'.$request->id.'/decision', [...$this->decision(), 'decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->post('/sales', $this->checkoutData($request))->assertSessionHasErrors('approval_id');
        $request = $this->approved();
        $this->product->update(['selling_price_minor' => 1100]);
        $this->post('/sales', $this->checkoutData($request))->assertSessionHasErrors('items.0.quoted_price');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_multi_line_discount_residual_reconciles_to_exact_cent(): void
    {
        $second = $this->product->replicate(['sku', 'name_key']);
        $second->sku = 'T2';
        $second->name_key = 'second hammer';
        $second->save();
        $second->forceFill(['quantity_on_hand' => '20.000', 'inventory_value_minor' => 10000, 'average_cost_minor' => 500])->save();
        $request = $this->approved(['discount_amount' => '0.01', 'credit_amount' => '0.00', 'items' => [
            ['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.00'],
            ['product_id' => $second->id, 'quantity' => '1', 'quoted_price' => '10.00'],
        ]]);
        $this->post('/sales', [...$this->checkoutData($request), 'cash_tendered' => '20.00'])->assertSessionHasNoErrors();
        $sale = Sale::sole();
        $this->assertSame(1999, $sale->total_minor);
        $this->assertEquals(1999, DB::table('sale_items')->sum('total_minor'));
        $this->assertEquals(1, DB::table('sale_items')->sum('discount_minor'));
        $this->assertSame(1, $sale->change_minor);
        $this->assertDatabaseCount('receivables', 0);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function proposal(array $overrides = []): array
    {
        return [...['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'payment_method' => 'cash', 'discount_amount' => '1.00', 'credit_amount' => '4.00', 'due_on' => '2026-11-30', 'reason' => 'Regular customer terms', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'quoted_price' => '10.00']]], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function decision(): array
    {
        return ['decision' => 'approved', 'reason' => 'Terms reviewed by owner', 'minutes' => 30, 'confirm' => true];
    }

    /** @param array<string, mixed> $overrides */
    private function approved(array $overrides = []): SaleRequest
    {
        $this->actingAs($this->employee)->post('/sale-requests', $this->proposal($overrides))->assertSessionHasNoErrors();
        $request = SaleRequest::latest('id')->firstOrFail();
        $this->actingAs($this->owner)->post('/owner/sale-requests/'.$request->id.'/decision', $this->decision())->assertSessionHasNoErrors();
        $this->actingAs($this->employee);

        return $request->refresh();
    }

    /** @return array<string, mixed> */
    private function checkoutData(SaleRequest $request): array
    {
        return ['submission_key' => $request->checkout_key, 'approval_id' => $request->id, 'customer_id' => $request->customer_id, 'payment_method' => $request->payment_method, 'cash_tendered' => '10.00', 'items' => array_map(fn (array $item) => array_intersect_key($item, array_flip(['product_id', 'quantity', 'quoted_price'])), $request->items)];
    }

    /** @return array<string, mixed> */
    private function collection(string $amount, string $method = 'cash'): array
    {
        return ['submission_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => $amount, 'method' => $method, ...($method === 'manual_mpesa' ? ['mpesa_code' => 'COLLECT123', 'expected_receiving_number' => '0712345678'] : [])];
    }

    private function debt(int $amount, string $date = '2026-11-01'): Receivable
    {
        return Receivable::create(['customer_id' => $this->customer->id, 'origin' => 'opening_debt', 'original_amount_minor' => $amount, 'remaining_amount_minor' => $amount, 'originated_on' => $date, 'due_on' => $date, 'source_note' => 'Test debt', 'actor_id' => $this->owner->id, 'created_at' => now()]);
    }
}
