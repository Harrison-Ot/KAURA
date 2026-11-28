<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ShopSettings;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Support\StockArithmetic;
use Database\Seeders\DevelopmentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DevelopmentDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_populates_real_workflows_preserves_existing_catalogue_and_balances_reconcile(): void
    {
        $category = Category::create(['name' => 'Existing', 'name_key' => 'existing']);
        $legacy = Product::create(['sku' => 'EXISTING', 'name' => 'Existing product', 'name_key' => 'existing product', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
        $this->seed(DevelopmentDemoSeeder::class);
        $this->assertDatabaseCount('products', 25);
        $this->assertDatabaseCount('customers', 6);
        $this->assertDatabaseCount('sales', 15);
        $this->assertDatabaseCount('sale_requests', 4);
        $this->assertDatabaseCount('stock_counts', 2);
        $this->assertDatabaseCount('customer_credits', 2);
        $this->assertDatabaseCount('customer_credit_payouts', 1);
        $this->assertSame('0.000', $legacy->refresh()->quantity_on_hand);
        $this->assertSame('Existing product', $legacy->name);
        $this->assertNotNull(ShopSettings::findOrFail(1)->opening_completed_at);
        foreach (Product::all() as $product) {
            $balance = 0;
            foreach (DB::table('stock_movements')->where('product_id', $product->id)->pluck('quantity_delta') as $quantity) {
                $balance += StockArithmetic::scaled((string) $quantity);
            }
            $this->assertSame(StockArithmetic::scaled($product->quantity_on_hand), $balance, $product->sku.' movement reconciliation');
        }
        $owner = User::where('email', 'owner@kaura.test')->sole();
        $employee = User::where('email', 'employee@kaura.test')->sole();
        $this->actingAs($employee)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('demoMode', true)->where('paymentAlerts.pending', 2)->where('paymentAlerts.disputed', 1));
        $this->get('/sales')->assertInertia(fn (Assert $page) => $page->has('sales.data', 14));
        $today = now('Africa/Nairobi')->toDateString();
        $report = app(FinancialReportService::class)->build($owner, $today, $today);
        $this->assertNotSame('0.00', $report['summary']['net_sales']);
        $this->assertNotNull($report['summary']['operating_profit_estimate']);
        $this->assertSame('650.00', $report['current']['retained_customer_credit']);
        $this->assertSame('650.00', $report['current']['void_confirmed_funds_held']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_rerun_never_resets_passwords_balances_or_user_edits(): void
    {
        $this->seed(DevelopmentDemoSeeder::class);
        $owner = User::where('email', 'owner@kaura.test')->sole();
        $owner->password = 'ChangedSecret!2026';
        $owner->save();
        $hash = $owner->password;
        $product = Product::where('sku', 'DEMO-CEMENT')->sole();
        $product->name = 'Edited demo cement';
        $product->save();
        $quantity = $product->quantity_on_hand;
        $counts = array_map(fn (string $table): int => DB::table($table)->count(), ['sales', 'payments', 'audit_events', 'stock_movements']);
        $this->seed(DevelopmentDemoSeeder::class);
        $this->assertSame($counts, array_map(fn (string $table): int => DB::table($table)->count(), ['sales', 'payments', 'audit_events', 'stock_movements']));
        $this->assertSame($hash, $owner->refresh()->password);
        $this->assertSame('Edited demo cement', $product->refresh()->name);
        $this->assertSame($quantity, $product->quantity_on_hand);
    }

    public function test_production_seeding_refuses_before_creating_records(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->expectException(\RuntimeException::class);
            (new DevelopmentDemoSeeder)->run();
        } finally {
            $this->app->instance('env', 'testing');
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_established_shop_refuses_without_mutating_existing_data(): void
    {
        ShopSettings::findOrFail(1)->update(['opening_completed_at' => now()]);
        try {
            (new DevelopmentDemoSeeder)->run();
            $this->fail('Expected established shop guard');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unposted shop', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_failed_seed_rolls_back_all_fixtures_and_marker(): void
    {
        Event::listen('eloquent.creating: App\Models\AuditEvent', function ($event): void {
            if ($event->getAttribute('event') === 'sale.posted') {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        try {
            (new DevelopmentDemoSeeder)->run();
            $this->fail('Expected posting failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertNull(ShopSettings::findOrFail(1)->opening_completed_at);
    }
}
