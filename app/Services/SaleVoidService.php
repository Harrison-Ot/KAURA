<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleVoid;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\StockArithmetic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaleVoidService
{
    public function void(User $actor, Sale $sale, string $reason): SaleVoid
    {
        Gate::forUser($actor)->authorize('void', $sale);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why this completed sale is being voided.']);
        }

        return PostingTransaction::run(function () use ($actor, $sale, $reason): SaleVoid {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $existing = SaleVoid::query()->where('sale_id', $sale->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($sale->getAttribute('status') !== 'posted') {
                throw ValidationException::withMessages(['void' => 'This sale is not available for voiding. Ask the owner to reconcile its history.']);
            }
            $debt = Receivable::query()->where('sale_id', $sale->id)->lockForUpdate()->first();
            $confirmed = (int) Payment::query()->where('sale_id', $sale->id)->where('status', 'verified')->sum('amount_minor');
            if ($debt !== null) {
                $confirmed += (int) DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->where('receivable_id', $debt->id)->where('payments.purpose', 'instalment')->where('payments.status', 'verified')->sum('payment_allocations.amount_minor');
            }
            $pending = Payment::query()->where('sale_id', $sale->id)->where('status', 'pending')->exists();
            $void = SaleVoid::create(['sale_id' => $sale->id, 'actor_id' => $actor->id, 'reason' => $reason, 'reversed_total_minor' => $sale->total_minor, 'reversed_cost_minor' => $sale->cost_total_minor, 'canceled_debt_minor' => $debt === null ? 0 : $debt->remaining_amount_minor, 'confirmed_minor' => $confirmed, 'funds_status' => $confirmed > 0 || $pending ? 'needs_review' : 'not_required', 'stock_status' => 'pending', 'created_at' => now()]);
            foreach ($sale->items as $item) {
                DB::table('sale_void_items')->insert(['sale_void_id' => $void->id, 'sale_item_id' => $item->id]);
            }
            if ($debt !== null) {
                $debt->remaining_amount_minor = 0;
                $debt->save();
                if ($debt->customer_id !== null) {
                    $customer = Customer::query()->lockForUpdate()->findOrFail($debt->customer_id);
                    $customer->version++;
                    $customer->save();
                }
            }
            $sale->setAttribute('status', 'voided');
            $sale->save();
            AccessAudit::record('sale.voided', $actor, null, $reason, ['status' => 'posted', 'balance_minor' => $void->getAttribute('canceled_debt_minor')], ['status' => 'voided', 'balance_minor' => 0, 'confirmed_funds_minor' => $confirmed, 'stock_status' => 'pending', 'funds_status' => $void->getAttribute('funds_status')], 'sale', $sale->id);

            return $void;
        }, 'void');
    }

    public function decideStock(User $actor, SaleVoid $void, string $decision, string $reason): void
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);
        if (! in_array($decision, ['restocked', 'not_restocked'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['decision' => 'Choose a stock disposition and explain the physical goods review.']);
        }
        PostingTransaction::run(function () use ($actor, $void, $decision, $reason): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $void = SaleVoid::query()->lockForUpdate()->findOrFail($void->id);
            if ($void->stock_status !== 'pending') {
                if ($void->stock_status === $decision) {
                    return;
                }
                throw ValidationException::withMessages(['decision' => 'Stock was already reviewed. Further changes require a new physical count.']);
            }
            if ($decision === 'restocked') {
                $items = SaleItem::query()->where('sale_id', $void->sale_id)->orderBy('product_id')->get();
                foreach ($items as $item) {
                    $product = Product::query()->where('id', $item->getAttribute('product_id'))->lockForUpdate()->firstOrFail();
                    $quantity = StockArithmetic::scaled($product->quantity_on_hand) + StockArithmetic::scaled($item->quantity);
                    if ($quantity > 999_999_999_999) {
                        throw ValidationException::withMessages(['decision' => 'Returned stock exceeds the supported balance. Nothing was restocked.']);
                    }
                    $value = $product->inventory_value_minor === null || $item->cost_total_minor === null ? null : StockArithmetic::total($product->inventory_value_minor, $item->cost_total_minor, 'decision');
                    if ($value !== null) {
                        StockArithmetic::total((int) Product::query()->where('id', '!=', $product->id)->sum('inventory_value_minor'), $value, 'decision');
                    }
                    $product->quantity_on_hand = StockArithmetic::decimal($quantity);
                    $product->inventory_value_minor = $value;
                    $product->average_cost_minor = $value === null ? null : StockArithmetic::average($value, $quantity);
                    $product->version++;
                    $product->save();
                    DB::table('stock_movements')->insert(['product_id' => $product->id, 'sale_void_item_id' => DB::table('sale_void_items')->where('sale_void_id', $void->id)->where('sale_item_id', $item->id)->sole()->id, 'kind' => 'sale_void', 'quantity_delta' => $item->quantity, 'balance_after' => $product->quantity_on_hand, 'value_delta_minor' => $item->cost_total_minor, 'effective_on' => now('Africa/Nairobi')->toDateString(), 'actor_id' => $actor->id, 'created_at' => now()]);
                }
            }
            $void->fill(['stock_status' => $decision, 'stock_decided_by' => $actor->id, 'stock_decided_at' => now(), 'stock_reason' => $reason])->save();
            AccessAudit::record('sale_void.stock_reviewed', $actor, null, $reason, ['stock_status' => 'pending'], ['stock_status' => $decision], 'sale_void', $void->id);
        }, 'decision');
    }
}
