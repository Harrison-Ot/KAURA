<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CheckoutConcurrencyTest extends TestCase
{
    #[DataProvider('scenarios')]
    public function test_interleaved_postings_preserve_stock_and_payment_uniqueness(string $scenario, string $quantity, string $paymentStatus, int $allocations, int $decisions, int $balance, ?string $error): void
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $database = $directory.'/checkout-race-'.Str::uuid().'.sqlite';
        touch($database);
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CHECKOUT_RACE_DB' => $database, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $script = base_path('tests/Support/checkout-race.php');
        $workers = [];
        try {
            $setup = new Process([PHP_BINARY, $script, 'setup', $scenario], base_path(), $environment, timeout: 30);
            $setup->mustRun();
            $this->assertTrue(json_decode($setup->getOutput(), true, 512, JSON_THROW_ON_ERROR)['ready']);
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, $script, 'attempt', (string) $worker, $scenario], base_path(), $environment, timeout: 30);
                $process->start();
                $workers[] = $process;
            }
            $results = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(match ($scenario) {
                'verification' => ['verified', 'verified'], 'count_approval' => ['approved', 'approved'], 'expense' => ['posted', 'posted'], 'void' => ['voided', 'voided'], 'void_restock' => ['restocked', 'restocked'], 'void_refund', 'void_credit', 'credit_payout' => ['resolved', 'resolved'], default => ['posted', 'rejected']
            }, $statuses);
            $this->assertTrue($results[0]['interleaved']);
            $this->assertTrue($results[1]['interleaved']);
            if ($error !== null) {
                $rejected = $results[0]['status'] === 'rejected' ? $results[0] : $results[1];
                $this->assertArrayHasKey($error, $rejected['errors']);
            }
            $verify = new Process([PHP_BINARY, $script, 'verify', $scenario], base_path(), $environment, timeout: 15);
            $verify->mustRun();
            $noSale = in_array($scenario, ['count_approval', 'expense'], true);
            $this->assertSame(['sales' => $noSale ? 0 : 1, 'payments' => match ($scenario) {
                'collection' => 2, 'count_approval', 'expense' => 0, default => 1
            }, 'sale_movements' => $noSale ? 0 : 1, 'quantity' => $quantity, 'inventory_value' => $quantity === '0.000' ? 0 : 500, 'allocations' => $allocations, 'decisions' => $decisions, 'balance_minor' => $balance, 'payment_status' => $paymentStatus, 'adjustments' => $scenario === 'count_approval' ? 1 : 0, 'outflows' => in_array($scenario, ['expense', 'void_refund', 'credit_payout'], true) ? 1 : 0, 'voids' => in_array($scenario, ['void', 'void_restock', 'void_refund', 'void_credit', 'credit_payout'], true) ? 1 : 0, 'void_movements' => $scenario === 'void_restock' ? 1 : 0, 'resolutions' => in_array($scenario, ['void_refund', 'void_credit', 'credit_payout'], true) ? 1 : 0, 'credits' => in_array($scenario, ['void_credit', 'credit_payout'], true) ? 1 : 0, 'credit_payouts' => $scenario === 'credit_payout' ? 1 : 0, 'credit_balance_minor' => $scenario === 'void_credit' ? 1000 : 0], json_decode($verify->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach (['', '-wal', '-shm', '.read-1', '.read-2'] as $suffix) {
                if (file_exists($database.$suffix)) {
                    unlink($database.$suffix);
                }
            }
        }
    }

    /** @return array<string, array{string, string, string, int, int, int, string|null}> */
    public static function scenarios(): array
    {
        return [
            'last unit cash' => ['cash', '0.000', 'verified', 0, 0, 0, 'items.0.quantity'],
            'duplicate incoming code' => ['manual_duplicate', '1.000', 'pending', 0, 0, 1000, 'mpesa_code'],
            'verify same payment twice' => ['verification', '0.000', 'verified', 1, 1, 0, null],
            'collect final balance twice' => ['collection', '0.000', 'verified', 1, 0, 0, 'amount'],
            'approve same count twice' => ['count_approval', '0.000', 'none', 0, 0, 0, null],
            'post same expense twice' => ['expense', '1.000', 'none', 0, 0, 0, null],
            'void same sale twice' => ['void', '0.000', 'verified', 0, 0, 0, null],
            'restock same void twice' => ['void_restock', '1.000', 'verified', 0, 0, 0, null],
            'refund same void twice' => ['void_refund', '0.000', 'verified', 0, 0, 0, null],
            'retain credit twice' => ['void_credit', '0.000', 'verified', 0, 0, 0, null],
            'pay retained credit twice' => ['credit_payout', '0.000', 'verified', 0, 0, 0, null],
        ];
    }
}
