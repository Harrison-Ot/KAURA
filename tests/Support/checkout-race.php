<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Models\StockCount;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CustomerCreditPayoutService;
use App\Services\InstalmentService;
use App\Services\InventoryService;
use App\Services\OwnerOutflowService;
use App\Services\PaymentVerificationService;
use App\Services\SaleVoidService;
use App\Services\StockCountService;
use App\Services\VoidResolutionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$target = getenv('CHECKOUT_RACE_DB');
$root = realpath(dirname(__DIR__, 2).'/storage/framework/testing');
if (PHP_SAPI !== 'cli' || ! is_string($target) || $root === false || realpath(dirname($target)) !== $root || preg_match('/^checkout-race-[a-f0-9-]+\.sqlite$/', basename($target)) !== 1) {
    throw new RuntimeException('Race helpers require an isolated test database.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath($target)) {
    throw new RuntimeException('Refusing to run race fixtures outside the isolated testing database.');
}
DB::statement('PRAGMA busy_timeout = 10000');
$mode = $argv[1] ?? '';
$scenario = $mode === 'attempt' ? ($argv[3] ?? 'cash') : ($argv[2] ?? 'cash');
if (! in_array($scenario, ['cash', 'manual_duplicate', 'verification', 'collection', 'count_approval', 'expense', 'void', 'void_restock', 'void_refund', 'void_credit', 'credit_payout'], true)) {
    throw new RuntimeException('Unknown race scenario.');
}
$manualData = ['submission_key' => (string) Str::uuid(), 'payment_method' => 'manual_mpesa', 'mpesa_code' => 'RACEABC123', 'mpesa_amount' => '10.00', 'expected_receiving_number' => '0712345678', 'items' => [['product_id' => 1, 'quantity' => '1', 'quoted_price' => '10.00']]];
if ($mode === 'setup') {
    DB::statement('PRAGMA journal_mode = WAL');
    Artisan::call('migrate', ['--force' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    foreach ([1, 2] as $worker) {
        User::factory()->create(['email' => 'checkout-race-'.$worker.'@example.test']);
    }
    ShopSettings::query()->findOrFail(1)->update(['shop_name' => 'Isolated concurrency fixture', 'mpesa_number' => '0712345678']);
    $category = Category::create(['name' => 'Race fixture', 'name_key' => 'race fixture']);
    $product = Product::create(['sku' => 'LAST-UNIT', 'name' => 'Last test unit', 'name_key' => 'last test unit', 'category_id' => $category->id, 'unit_label' => 'piece', 'quantity_precision' => 0, 'selling_price_minor' => 1000, 'reorder_threshold' => '0']);
    $service = app(InventoryService::class);
    $day = now('Africa/Nairobi')->toDateString();
    $batch = $service->draft($owner, ['kind' => 'opening_stock', 'submission_key' => (string) Str::uuid(), 'effective_on' => $day, 'source_note' => 'Isolated test fixture', 'rows' => [['sku' => $product->sku, 'unit' => 'piece', 'quantity' => $scenario === 'manual_duplicate' ? '2' : '1', 'unit_cost' => '5.00']]]);
    $service->post($owner, $batch, 'Test review');
    $service->completeOpening($owner, '0.00', 'Test reconciliation', $day);
    if (in_array($scenario, ['void', 'void_restock', 'void_refund', 'void_credit', 'credit_payout'], true)) {
        $sale = app(CheckoutService::class)->checkout($owner, ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => '10.00', 'items' => [['product_id' => 1, 'quantity' => '1', 'quoted_price' => '10.00']]]);
        if ($scenario !== 'void') {
            $void = app(SaleVoidService::class)->void($owner, $sale, 'Test cancellation');
            if (in_array($scenario, ['void_refund', 'void_credit', 'credit_payout'], true)) {
                app(SaleVoidService::class)->decideStock($owner, $void, 'not_restocked', 'Owner accounted for unavailable goods');
                Customer::forceCreate(['name' => 'Credit customer', 'name_key' => 'credit customer', 'contact' => '0700000001', 'contact_key' => '0700000001', 'created_by' => $owner->id]);
                if ($scenario === 'credit_payout') {
                    app(VoidResolutionService::class)->resolve($owner, $void, ['submission_key' => (string) Str::uuid(), 'kind' => 'customer_credit', 'customer_id' => 1, 'recipient' => 'Test customer', 'reason' => 'Retained credit fixture']);
                }
            }
        }
    }
    if ($scenario === 'count_approval') {
        app(StockCountService::class)->submit($owner, ['submission_key' => (string) Str::uuid(), 'reason' => 'Test physical count', 'items' => [['product_id' => $product->id, 'expected_version' => $product->refresh()->version, 'counted_quantity' => '0']]]);
    }
    if ($scenario === 'verification') {
        app(CheckoutService::class)->checkout(User::query()->where('email', 'checkout-race-1@example.test')->firstOrFail(), $manualData);
    }
    if ($scenario === 'collection') {
        app(CheckoutService::class)->checkout($owner, ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => '10.00', 'items' => [['product_id' => 1, 'quantity' => '1', 'quoted_price' => '10.00']]]);
        $customer = Customer::forceCreate(['name' => 'Race customer', 'name_key' => 'race customer', 'contact' => '0700000001', 'contact_key' => '0700000001', 'created_by' => $owner->id]);
        DB::table('receivables')->insert(['customer_id' => $customer->id, 'origin' => 'opening_balance', 'original_amount_minor' => 1000, 'remaining_amount_minor' => 1000, 'originated_on' => $day, 'due_on' => $day, 'source_note' => 'Isolated debt fixture', 'actor_id' => $owner->id, 'created_at' => now()]);
    }
    echo json_encode(['ready' => true], JSON_THROW_ON_ERROR);
} elseif ($mode === 'attempt') {
    $worker = (int) ($argv[2] ?? 0);
    if (! in_array($worker, [1, 2], true)) {
        throw new RuntimeException('Unknown test worker.');
    }
    $actor = in_array($scenario, ['verification', 'count_approval', 'expense', 'void', 'void_restock', 'void_refund', 'void_credit', 'credit_payout'], true) ? User::query()->where('role', 'owner')->firstOrFail() : User::query()->where('email', 'checkout-race-'.$worker.'@example.test')->firstOrFail();
    $interleaved = false;
    DB::listen(function (QueryExecuted $query) use ($target, $worker, $scenario, &$interleaved): void {
        $table = $scenario === 'expense' ? 'from "cash_outflows"' : (in_array($scenario, ['verification', 'collection'], true) ? 'from "receivables"' : 'from "products"');
        if ($scenario === 'void') {
            $table = 'from "sale_voids"';
        }
        if (in_array($scenario, ['void_refund', 'void_credit'], true)) {
            $table = 'from "void_resolutions"';
        }
        if ($scenario === 'credit_payout') {
            $table = 'from "customer_credit_payouts"';
        }
        if ($interleaved || ! str_contains($query->sql, $table)) {
            return;
        }
        $interleaved = true;
        file_put_contents($target.'.read-'.$worker, 'ready');
        $other = $target.'.read-'.($worker === 1 ? 2 : 1);
        $deadline = microtime(true) + 10;
        do {
            clearstatcache(true, $other);
            if (file_exists($other)) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The second checkout did not reach its stock read.');
    });
    try {
        if ($scenario === 'credit_payout') {
            app(CustomerCreditPayoutService::class)->pay($actor, 1, ['submission_key' => '00000000-0000-4000-8000-000000000011', 'payment_method' => 'cash', 'recipient' => 'Test customer', 'reason' => 'Owner returned retained credit']);
            echo json_encode(['status' => 'resolved', 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } elseif (in_array($scenario, ['void_refund', 'void_credit'], true)) {
            app(VoidResolutionService::class)->resolve($actor, SaleVoid::query()->sole(), ['submission_key' => '00000000-0000-4000-8000-000000000010', 'kind' => $scenario === 'void_refund' ? 'refund' : 'customer_credit', ...($scenario === 'void_refund' ? ['payment_method' => 'cash'] : ['customer_id' => 1]), 'recipient' => 'Test customer', 'reason' => 'Owner agreed full funds disposition']);
            echo json_encode(['status' => 'resolved', 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } elseif ($scenario === 'void') {
            app(SaleVoidService::class)->void($actor, Sale::query()->sole(), 'Test cancellation');
            echo json_encode(['status' => 'voided', 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } elseif ($scenario === 'void_restock') {
            app(SaleVoidService::class)->decideStock($actor, SaleVoid::query()->sole(), 'restocked', 'Owner physically checked every unit');
            echo json_encode(['status' => 'restocked', 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } elseif ($scenario === 'expense') {
            app(OwnerOutflowService::class)->record($actor, ['submission_key' => '00000000-0000-4000-8000-000000000008', 'kind' => 'expense', 'category' => 'transport', 'amount' => '10.00', 'payment_method' => 'cash', 'recipient' => 'Test recipient', 'reason' => 'Isolated expense fixture']);
            echo json_encode(['status' => 'posted', 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } elseif ($scenario === 'count_approval') {
            app(StockCountService::class)->decide($actor, StockCount::query()->sole(), 'approved', 'Owner checked count');
            echo json_encode(['status' => 'approved', 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } elseif ($scenario === 'verification') {
            $payment = Payment::query()->sole();
            app(PaymentVerificationService::class)->decide($actor, $payment, 'verified', 'Owner checked test statement.');
            echo json_encode(['status' => $payment->refresh()->status, 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } elseif ($scenario === 'collection') {
            app(InstalmentService::class)->collect($actor, ['submission_key' => (string) Str::uuid(), 'customer_id' => 1, 'amount' => '10.00', 'method' => 'cash']);
            echo json_encode(['status' => 'posted', 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        } else {
            $sale = app(CheckoutService::class)->checkout($actor, $scenario === 'manual_duplicate' ? $manualData : ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_tendered' => '10.00', 'items' => [['product_id' => 1, 'quantity' => '1', 'quoted_price' => '10.00']]]);
            echo json_encode(['status' => 'posted', 'sale_id' => $sale->id, 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
        }
    } catch (ValidationException $exception) {
        echo json_encode(['status' => 'rejected', 'errors' => $exception->errors(), 'interleaved' => $interleaved], JSON_THROW_ON_ERROR);
    }
} elseif ($mode === 'verify') {
    echo json_encode([
        'sales' => DB::table('sales')->count(), 'payments' => DB::table('payments')->count(),
        'sale_movements' => DB::table('stock_movements')->where('kind', 'sale')->count(),
        'quantity' => Product::query()->findOrFail(1)->quantity_on_hand,
        'inventory_value' => Product::query()->findOrFail(1)->inventory_value_minor,
        'allocations' => DB::table('payment_allocations')->count(), 'decisions' => DB::table('payment_verifications')->count(),
        'balance_minor' => (int) DB::table('receivables')->sum('remaining_amount_minor'), 'payment_status' => Payment::query()->latest('id')->first()?->status ?? 'none',
        'adjustments' => DB::table('stock_movements')->where('kind', 'stock_adjustment')->count(),
        'outflows' => DB::table('cash_outflows')->count(),
        'voids' => DB::table('sale_voids')->count(),
        'void_movements' => DB::table('stock_movements')->where('kind', 'sale_void')->count(),
        'resolutions' => DB::table('void_resolutions')->count(),
        'credits' => DB::table('customer_credits')->count(),
        'credit_payouts' => DB::table('customer_credit_payouts')->count(),
        'credit_balance_minor' => (int) DB::table('customer_credits')->sum('remaining_amount_minor'),
    ], JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown test mode.');
}
