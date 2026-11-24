<?php

namespace App\Services;

use App\Models\CashOutflow;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\StockArithmetic as Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopOperationService
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public function quoteReturn(User $actor, array $data): array
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);
        return PostingTransaction::run(function () use ($data): array {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            [$record] = $this->returnItems($data, []);
            return ['amount_minor' => (string) $record['amount_minor'], 'cash_amount_minor' => (string) $record['cash_amount_minor'], 'debt_amount_minor' => (string) $record['debt_amount_minor']];
        }, 'operation');
    }

    /** @param array<string, mixed> $data */
    public function post(User $actor, array $data): int
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);
        unset($data['confirm']);

        return PostingTransaction::run(function () use ($actor, $data): int {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = DB::table('shop_operations')->where('submission_key', $data['submission_key'])->first();
            if ($existing !== null) {
                if ($existing->payload_hash !== $hash || (int) $existing->actor_id !== $actor->id) {
                    $this->fail('This submission was already used with different terms.');
                }

                return (int) $existing->id;
            }
            if ($settings->opening_completed_at === null) {
                $this->fail('Sign off opening balances first.');
            }
            $record = ['submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'kind' => $data['kind'], 'actor_id' => $actor->id, 'reason' => $data['reason'], 'business_date' => now('Africa/Nairobi')->toDateString(), 'created_at' => now(), 'amount_minor' => 0, 'cash_amount_minor' => 0, 'debt_amount_minor' => 0];
            $items = [];
            if ($data['kind'] === 'return') {
                [$record, $items] = $this->returnItems($data, $record);
                foreach (['amount_minor', 'cash_amount_minor', 'debt_amount_minor'] as $field) {
                    if (isset($data['expected_'.$field]) && (string) $data['expected_'.$field] !== (string) $record[$field]) {
                        $this->fail('The return balances changed. Refresh the financial preview before paying or posting.');
                    }
                }
                if ($record['receivable_id'] !== null) {
                    DB::table('receivables')->where('id', $record['receivable_id'])->decrement('remaining_amount_minor', $record['debt_amount_minor']);
                }
            } elseif (in_array($data['kind'], ['write_off', 'credit_offset'], true)) {
                $debt = DB::table('receivables')->where('id', $data['receivable_id'])->lockForUpdate()->first();
                abort_if($debt === null || $debt->customer_id === null, 404);
                $this->noPending((int) $debt->customer_id);
                $amount = Money::totalAmount((string) $data['amount'], 'amount');
                if ($amount <= 0 || $amount > (int) $debt->remaining_amount_minor) {
                    $this->fail('Amount must be positive and cannot exceed the remaining debt.');
                }
                $record += ['customer_id' => $debt->customer_id, 'receivable_id' => $debt->id, 'sale_id' => $debt->sale_id];
                $record['amount_minor'] = $record['debt_amount_minor'] = $amount;
                if ($data['kind'] === 'credit_offset') {
                    $credit = DB::table('customer_credits')->where('id', $data['customer_credit_id'])->lockForUpdate()->first();
                    if ($credit === null || (int) $credit->customer_id !== (int) $debt->customer_id || $amount > (int) $credit->remaining_amount_minor) {
                        $this->fail('Choose credit belonging to this customer with sufficient remaining balance.');
                    }
                    $oldest = DB::table('receivables')->where('customer_id', $debt->customer_id)->where('remaining_amount_minor', '>', 0)->orderBy('originated_on')->orderBy('id')->value('id');
                    if ((int) $oldest !== (int) $debt->id) {
                        $this->fail('Apply customer credit to the oldest outstanding debt first.');
                    }
                    $record['customer_credit_id'] = $credit->id;
                    DB::table('customer_credits')->where('id', $credit->id)->decrement('remaining_amount_minor', $amount);
                }
                DB::table('receivables')->where('id', $debt->id)->decrement('remaining_amount_minor', $amount);
            } else {
                $outflow = CashOutflow::query()->lockForUpdate()->where('id', $data['cash_outflow_id'])->firstOrFail();
                if (! in_array($outflow->kind, ['expense', 'owner_withdrawal', 'stock_purchase'], true)) {
                    $this->fail('Refunds cannot be reversed through this workflow.');
                }
                $record += ['cash_outflow_id' => $outflow->id, 'source_key' => 'outflow:'.$outflow->id];
                if (DB::table('shop_operations')->where('source_key', $record['source_key'])->exists()) {
                    $this->fail('This payment has already been reversed.');
                }
                $record['amount_minor'] = $outflow->amount_minor;
                $record['cash_amount_minor'] = -$outflow->amount_minor;
                $record['kind'] = $outflow->kind === 'stock_purchase' ? 'receipt_reversal' : 'outflow_reversal';
                if ($outflow->kind === 'stock_purchase') {
                    $record['inventory_batch_id'] = $outflow->batch_id;
                    foreach (DB::table('inventory_batch_items')->where('batch_id', $outflow->batch_id)->get() as $item) {
                        $movement = DB::table('stock_movements')->where('batch_item_id', $item->id)->first();
                        if ($movement === null || DB::table('stock_movements')->where('product_id', $item->product_id)->where('id', '>', $movement->id)->exists()) {
                            $this->fail('This delivery has later stock activity. Use a reviewed stock count and separate financial correction instead.');
                        }
                        $items[] = ['product_id' => $item->product_id, 'quantity' => $item->quantity, 'amount_minor' => $item->amount_minor, 'cost_minor' => $item->amount_minor, 'disposition' => 'supplier_return'];
                    }
                }
            }
            if ($record['cash_amount_minor'] !== 0) {
                $record['payment_method'] = $data['payment_method'];
                $record['recipient'] = $data['recipient'];
                $reference = $data['payment_method'] === 'mpesa' ? ($data['payment_reference'] ?? null) : null;
                if ($reference !== null && (Payment::query()->where('mpesa_code', $reference)->exists() || CashOutflow::query()->where('payment_reference', $reference)->exists() || DB::table('shop_operations')->where('payment_reference', $reference)->exists())) {
                    $this->fail('This M-Pesa transaction code was already used.');
                }
                $record['payment_reference'] = $reference;
            }
            $id = DB::table('shop_operations')->insertGetId($record);
            foreach ($items as $item) {
                $itemId = DB::table('shop_operation_items')->insertGetId(['shop_operation_id' => $id, ...$item]);
                if (in_array($item['disposition'], ['restock', 'supplier_return'], true)) {
                    $this->moveStock($actor, $itemId, $item, $item['disposition'] === 'supplier_return' ? -1 : 1);
                }
            }
            if (isset($record['customer_id'])) {
                Customer::query()->where('id', $record['customer_id'])->increment('version');
            }
            AccessAudit::record('shop_operation.'.$record['kind'], $actor, null, $data['reason'], null, $record, 'shop_operation', $id);

            return $id;
        }, 'operation');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $record
     * @return array{array<string, mixed>, list<array<string, mixed>>}
     */
    private function returnItems(array $data, array $record): array
    {
        $sale = Sale::query()->lockForUpdate()->where('id', $data['sale_id'])->firstOrFail();
        if ($sale->getAttribute('status') !== 'posted' || Payment::query()->where('sale_id', $sale->id)->where('status', 'pending')->exists()) {
            $this->fail('Returns require a posted sale with all pending checkout payments resolved.');
        }
        $debt = DB::table('receivables')->where('sale_id', $sale->id)->lockForUpdate()->first();
        if ($debt?->customer_id !== null) {
            $this->noPending((int) $debt->customer_id);
        }
        $items = [];
        $total = 0;
        $cost = 0;
        $complete = true;
        foreach ($data['items'] as $row) {
            $line = $sale->items()->where('id', $row['sale_item_id'])->firstOrFail();
            $product = Product::query()->where('id', $line->getAttribute('product_id'))->firstOrFail();
            $quantity = Money::quantity((string) $row['quantity'], $product->quantity_precision, 'items');
            $sold = Money::scaled($line->quantity);
            $previous = DB::table('shop_operation_items')->where('sale_item_id', $line->id)->get();
            $returned = $previous->sum(fn (object $item): int => Money::scaled((string) $item->quantity));
            if ($quantity <= 0 || $quantity + $returned > $sold) {
                $this->fail('Return quantities must be positive and cannot exceed the unreturned quantity.');
            }
            $amount = $this->portion($line->total_minor, $returned + $quantity, $sold) - (int) $previous->sum('amount_minor');
            $lineCost = $line->cost_total_minor === null ? null : $this->portion($line->cost_total_minor, $returned + $quantity, $sold) - (int) $previous->sum('cost_minor');
            $total = Money::total($total, $amount);
            $complete = $complete && $lineCost !== null;
            $cost = Money::total($cost, $lineCost ?? 0);
            $items[] = ['product_id' => $product->id, 'sale_item_id' => $line->id, 'quantity' => Money::decimal($quantity), 'amount_minor' => $amount, 'cost_minor' => $lineCost, 'disposition' => $row['disposition']];
        }
        $debtReduction = min($total, (int) ($debt->remaining_amount_minor ?? 0));
        $paid = (int) Payment::query()->where('sale_id', $sale->id)->where('status', 'verified')->sum('amount_minor');
        if ($debt !== null) {
            $paid += (int) DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->where('receivable_id', $debt->id)->where('payments.purpose', 'instalment')->where('payments.status', 'verified')->sum('payment_allocations.amount_minor');
            $paid += (int) DB::table('shop_operations')->where('receivable_id', $debt->id)->whereIn('kind', ['credit_offset', 'credit_redemption'])->sum('amount_minor');

        }
        $earlierRefunds = (int) DB::table('shop_operations')->where('sale_id', $sale->id)->where('kind', 'return')->sum('cash_amount_minor');
        $refund = min($total - $debtReduction, max(0, $paid - $earlierRefunds));
        $record += ['sale_id' => $sale->id, 'customer_id' => $debt?->customer_id, 'receivable_id' => $debt?->id];
        $record['amount_minor'] = $total;
        $record['cash_amount_minor'] = $refund;
        $record['debt_amount_minor'] = $debtReduction;
        $record['cost_minor'] = $complete ? $cost : null;

        return [$record, $items];
    }

    private function portion(int $amount, int $quantity, int $sold): int
    {
        return BigDecimal::of($amount)->multipliedBy($quantity)->dividedBy($sold, 0, RoundingMode::HalfUp)->toInt();
    }

    /** @param array<string, mixed> $item */
    private function moveStock(User $actor, int $itemId, array $item, int $sign): void
    {
        $product = Product::query()->lockForUpdate()->where('id', $item['product_id'])->firstOrFail();
        $quantity = Money::scaled($product->quantity_on_hand) + $sign * Money::scaled((string) $item['quantity']);
        if ($quantity < 0 || $quantity > 999_999_999_999) {
            $this->fail('Stock balance would be outside the supported range.');
        }
        $value = $product->inventory_value_minor === null || $item['cost_minor'] === null ? null : BigDecimal::of($product->inventory_value_minor)->plus(BigDecimal::of((string) $item['cost_minor'])->multipliedBy($sign))->toInt();
        if ($value !== null && ($value < 0 || $value > Money::MAX_TOTAL_MINOR)) {
            $this->fail('Stock valuation would be outside the supported range.');
        }
        if ($value !== null) {
            Money::total((int) Product::query()->where('id', '!=', $product->id)->sum('inventory_value_minor'), $value);
        }
        $product->quantity_on_hand = Money::decimal($quantity);
        $product->inventory_value_minor = $quantity === 0 ? 0 : $value;
        $product->average_cost_minor = $value !== null && $quantity > 0 ? Money::average($value, $quantity) : null;
        $product->version++;
        $product->save();
        DB::table('stock_movements')->insert(['product_id' => $product->id, 'shop_operation_item_id' => $itemId, 'kind' => $sign < 0 ? 'receipt_reversal' : 'sale_return', 'quantity_delta' => Money::decimal($sign * Money::scaled((string) $item['quantity'])), 'balance_after' => $product->quantity_on_hand, 'value_delta_minor' => $item['cost_minor'] === null ? null : $sign * $item['cost_minor'], 'effective_on' => now('Africa/Nairobi')->toDateString(), 'actor_id' => $actor->id, 'created_at' => now()]);
    }

    private function noPending(int $customerId): void
    {
        if (Payment::query()->where('customer_id', $customerId)->where('status', 'pending')->exists()) {
            $this->fail('Resolve this customer’s pending M-Pesa payments before adjusting debt.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
