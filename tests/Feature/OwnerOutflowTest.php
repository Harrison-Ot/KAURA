<?php

namespace Tests\Feature;

use App\Models\CashOutflow;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OwnerOutflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerOutflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(22, 30));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->employee = User::factory()->create();
        ShopSettings::findOrFail(1)->update(['shop_name' => 'Test shop']);
        app(InventoryService::class)->completeOpening($this->owner, '0.00', 'Opening reviewed', '2026-11-21');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_expense_posts_exact_money_once_with_authenticated_owner_and_nairobi_day(): void
    {
        $data = $this->data();
        $this->post('/owner/outflows', $data)->assertSessionHasNoErrors();
        $this->post('/owner/outflows', $data)->assertSessionHasNoErrors();
        $entry = CashOutflow::sole();
        $this->assertSame(12345, $entry->amount_minor);
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertDatabaseHas('cash_outflows', ['kind' => 'expense', 'category' => 'transport', 'effective_on' => '2026-11-23', 'batch_id' => null]);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'outflow.recorded')->count());
        $this->get('/owner/outflows/'.$entry->id)->assertInertia(fn (Assert $page) => $page->where('outflow.amount', '123.45')->where('outflow.recipient', 'Delivery rider')->missing('outflow.payload_hash'));
        $this->post('/owner/outflows', [...$data, 'amount' => '20.00'])->assertSessionHasErrors('submission_key');
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_withdrawals_are_separate_from_expenses_and_method_totals(): void
    {
        $this->post('/owner/outflows', $this->data())->assertSessionHasNoErrors();
        $this->post('/owner/outflows', $this->withdrawal())->assertSessionHasNoErrors();
        $this->get('/owner/outflows')->assertInertia(fn (Assert $page) => $page->where('totals.expense', '123.45')->where('totals.owner_withdrawal', '50.01')->where('totals.stock_purchase', '0.00')->where('totals.cash', '123.45')->where('totals.mpesa', '50.01')->has('outflows.data', 2));
        $this->assertDatabaseHas('cash_outflows', ['kind' => 'owner_withdrawal', 'category' => null, 'payment_reference' => 'WITHDRAW123']);
        $this->get('/owner/outflows?kind=owner_withdrawal&method=mpesa')->assertInertia(fn (Assert $page) => $page->has('outflows.data', 1)->where('totals.expense', '0.00')->where('totals.owner_withdrawal', '50.01'));
    }

    public function test_employees_cannot_access_ledger_detail_create_or_export(): void
    {
        $this->post('/owner/outflows', $this->data())->assertSessionHasNoErrors();
        $entry = CashOutflow::sole();
        $this->actingAs($this->employee);
        foreach (['/owner/outflows', '/owner/outflows/create', '/owner/outflows/export', '/owner/outflows/'.$entry->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/owner/outflows', $this->data())->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(OwnerOutflowService::class)->record($this->employee, $this->data());
    }

    public function test_invalid_money_category_confirmation_or_forged_fields_cannot_post(): void
    {
        foreach ([['amount' => '0.00'], ['amount' => '-1.00'], ['amount' => '1.234'], ['category' => 'stock_purchase'], ['confirm' => false], ['actor_id' => $this->employee->id], ['effective_on' => '2026-11-21'], ['kind' => 'stock_purchase']] as $override) {
            $field = array_key_first($override);
            $this->post('/owner/outflows', [...$this->data(), ...$override])->assertSessionHasErrors($field);
        }
        $this->post('/owner/outflows', [...$this->withdrawal(), 'category' => 'wages'])->assertSessionHasErrors('category');
        $this->assertDatabaseCount('cash_outflows', 0);
    }

    public function test_opening_signoff_is_required(): void
    {
        ShopSettings::findOrFail(1)->update(['opening_completed_at' => null]);
        $this->get('/owner/outflows/create')->assertInertia(fn (Assert $page) => $page->where('openingReady', false));
        $this->post('/owner/outflows', $this->data())->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('cash_outflows', 0);
    }

    public function test_mpesa_codes_are_normalized_and_cannot_repeat_in_another_outgoing_entry(): void
    {
        $data = $this->withdrawal();
        $data['payment_reference'] = '  withdraw123 ';
        $this->post('/owner/outflows', $data)->assertSessionHasNoErrors();
        $this->post('/owner/outflows', $this->withdrawal())->assertSessionHasErrors('payment_reference');
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertDatabaseHas('cash_outflows', ['payment_reference' => 'WITHDRAW123']);
    }

    public function test_incoming_and_outgoing_codes_cannot_be_reused_across_ledgers(): void
    {
        Payment::create(['reference' => 'PAY-TEST', 'posting_key' => 'test-incoming', 'purpose' => 'instalment', 'handled_by' => $this->owner->id, 'method' => 'manual_mpesa', 'status' => 'pending', 'amount_minor' => 5001, 'business_date' => '2026-11-23', 'received_at' => now(), 'mpesa_code' => 'WITHDRAW123', 'receiving_number' => '0712345678']);
        $this->post('/owner/outflows', $this->withdrawal())->assertSessionHasErrors('payment_reference');
        $this->assertDatabaseCount('cash_outflows', 0);
    }

    public function test_audit_failure_rolls_back_money_out(): void
    {
        Event::listen('eloquent.creating: App\Models\AuditEvent', function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/owner/outflows', $this->data());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('cash_outflows', 0);
    }

    public function test_day_filters_are_inclusive_and_export_matches_without_spreadsheet_formulas(): void
    {
        $this->post('/owner/outflows', [...$this->data(), 'recipient' => '=HYPERLINK("bad")', 'reason' => ' +SUM(1,2)'])->assertSessionHasNoErrors();
        $this->travel(1)->days();
        $this->post('/owner/outflows', $this->withdrawal())->assertSessionHasNoErrors();
        $query = '?from=2026-11-23&to=2026-11-23&kind=expense&method=cash';
        $this->get('/owner/outflows'.$query)->assertInertia(fn (Assert $page) => $page->has('outflows.data', 1)->where('totals.expense', '123.45'));
        $response = $this->get('/owner/outflows/export'.$query)->assertOk()->assertDownload('kaura-money-out.csv');
        $csv = $response->streamedContent();
        $lines = preg_split('/\r?\n/', trim($csv));
        $this->assertCount(2, $lines);
        $row = str_getcsv($lines[1], ',', '"', '');
        $this->assertSame('123.45', $row[4]);
        $this->assertSame("'=HYPERLINK(\"bad\")", $row[7]);
        $this->assertSame("'+SUM(1,2)", $row[8]);
        $this->assertDatabaseHas('audit_events', ['event' => 'outflow.exported', 'actor_id' => $this->owner->id]);
        $this->get('/owner/outflows?from=2026-11-24&to=2026-11-23')->assertSessionHasErrors('to');
    }

    public function test_supplier_purchases_appear_once_and_migration_roundtrip_preserves_them(): void
    {
        $category = Category::create(['name' => 'Tools', 'name_key' => 'tools']);
        $product = Product::create(['sku' => 'T1', 'name' => 'Tool', 'name_key' => 'tool', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $service = app(InventoryService::class);
        $batch = $service->draft($this->owner, ['kind' => 'stock_receipt', 'submission_key' => (string) Str::uuid(), 'effective_on' => '2026-11-23', 'source_note' => 'Paid supplier invoice', 'supplier_name' => 'Supplier One', 'delivery_reference' => 'DEL-1', 'payment_method' => 'cash', 'amount_paid' => '10.00', 'rows' => [['sku' => $product->sku, 'unit' => 'piece', 'quantity' => '2', 'unit_cost' => '5.00']]]);
        $service->post($this->owner, $batch, 'Reviewed delivery');
        $this->get('/owner/outflows')->assertInertia(fn (Assert $page) => $page->where('totals.stock_purchase', '10.00')->where('totals.expense', '0.00')->where('outflows.data.0.recipient', 'Supplier One'));
        $payoutMigration = require database_path('migrations/2026_11_23_000011_create_customer_credit_payouts.php');
        $payoutMigration->down();
        $resolutionMigration = require database_path('migrations/2026_11_23_000010_create_void_resolutions.php');
        $resolutionMigration->down();
        $migration = require database_path('migrations/2026_11_22_000008_add_owner_outflows.php');
        $migration->down();
        $migration->up();
        $resolutionMigration->up();
        $payoutMigration->up();
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertDatabaseHas('cash_outflows', ['batch_id' => $batch->id, 'amount_minor' => 1000]);
        $this->assertSame('2.000', $product->refresh()->quantity_on_hand);
    }

    public function test_rollback_refuses_owner_entries_and_original_amount_cannot_be_edited_by_http(): void
    {
        $this->post('/owner/outflows', $this->withdrawal())->assertSessionHasNoErrors();
        $entry = CashOutflow::sole();
        $this->patch('/owner/outflows/'.$entry->id, ['amount' => '1.00'])->assertMethodNotAllowed();
        $this->delete('/owner/outflows/'.$entry->id)->assertMethodNotAllowed();
        $migration = require database_path('migrations/2026_11_22_000008_add_owner_outflows.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_ledger_pagination_does_not_truncate_totals_or_csv(): void
    {
        for ($i = 0; $i < 21; $i++) {
            app(OwnerOutflowService::class)->record($this->owner, [...$this->data(), 'amount' => '1.01']);
        }
        $this->get('/owner/outflows')->assertInertia(fn (Assert $page) => $page->has('outflows.data', 20)->where('outflows.total', 21)->where('totals.expense', '21.21'));
        $this->get('/owner/outflows?page=2')->assertInertia(fn (Assert $page) => $page->has('outflows.data', 1)->where('totals.expense', '21.21'));
        $csv = $this->get('/owner/outflows/export')->assertOk()->streamedContent();
        $this->assertCount(22, preg_split('/\r?\n/', trim($csv)));
        $this->assertStringContainsString('OUT-000021', $csv);
    }

    public function test_aggregate_overflow_cannot_post_partial_money_or_audit(): void
    {
        $this->post('/owner/outflows', [...$this->data(), 'amount' => '90000000000000.00'])->assertSessionHasNoErrors();
        $this->post('/owner/outflows', [...$this->data(), 'amount' => '0.01'])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('cash_outflows', 1);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'outflow.recorded')->count());
    }

    public function test_database_rejects_outflow_without_purchase_or_owner_evidence(): void
    {
        $this->expectException(QueryException::class);
        DB::table('cash_outflows')->insert(['kind' => 'expense', 'payment_method' => 'cash', 'amount_minor' => 100, 'effective_on' => '2026-11-23', 'actor_id' => $this->owner->id, 'created_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'kind' => 'expense', 'category' => 'transport', 'amount' => '123.45', 'payment_method' => 'cash', 'recipient' => 'Delivery rider', 'reason' => 'Paid for delivery transport, receipt 001', 'confirm' => true];
    }

    /** @return array<string, mixed> */
    private function withdrawal(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'kind' => 'owner_withdrawal', 'amount' => '50.01', 'payment_method' => 'mpesa', 'payment_reference' => 'WITHDRAW123', 'recipient' => 'Shop owner', 'reason' => 'Personal funds withdrawal', 'confirm' => true];
    }
}
