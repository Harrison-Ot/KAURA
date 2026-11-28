<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopSettings;
use App\Models\StockCount;
use App\Models\User;
use App\Support\StockArithmetic;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockCountService
{
    /** @param array<string, mixed> $data */
    public function submit(User $actor, array $data): StockCount
    {
        abort_unless($actor->is_active, 403);

        return PostingTransaction::run(function () use ($actor, $data): StockCount {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = StockCount::query()->where('submission_key', $data['submission_key'])->first();
            if ($existing !== null) {
                abort_unless($existing->counted_by === $actor->id, 403);
                if ($hash !== $existing->payload_hash) {
                    throw ValidationException::withMessages(['count' => 'This key belongs to a different count. Start a new count.']);
                }

                return $existing;
            }
            if ($settings->opening_completed_at === null) {
                throw ValidationException::withMessages(['count' => 'Sign off opening balances first. Use opening stock review for initial counts.']);
            }
            $products = Product::query()->whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $items = [];
            foreach ($data['items'] as $index => $row) {
                $product = $products->get((int) $row['product_id']);
                if ($product === null || $product->version !== (int) $row['expected_version']) {
                    throw ValidationException::withMessages(['items.'.$index.'.counted_quantity' => 'Stock or product details changed. Refresh and physically recount before submitting.']);
                }
                $quantity = StockArithmetic::quantity((string) $row['counted_quantity'], $product->quantity_precision, 'items.'.$index.'.counted_quantity');
                $system = StockArithmetic::scaled($product->quantity_on_hand);
                $items[] = ['product_id' => $product->id, 'position' => $index + 1, 'record_version' => $product->version, 'name' => $product->name, 'sku' => $product->sku, 'unit_label' => $product->unit_label,
                    'system_quantity' => $product->quantity_on_hand, 'counted_quantity' => StockArithmetic::decimal($quantity), 'quantity_delta' => StockArithmetic::decimal($quantity - $system), 'value_before_minor' => $product->inventory_value_minor];
            }
            $count = StockCount::create(['reference' => 'COUNT-'.Str::ulid(), 'submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'counted_by' => $actor->id, 'counted_at' => now(), 'reason' => $data['reason'], 'status' => 'pending']);
            $count->items()->createMany($items);
            AccessAudit::record('stock_count.submitted', $actor, null, $data['reason'], null, ['reference' => $count->reference, 'lines' => count($items)], 'stock_count', $count->id);

            return $count;
        }, 'count');
    }

    public function decide(User $actor, StockCount $count, string $decision, string $reason): void
    {
        Gate::forUser($actor)->authorize('decide', $count);
        if (! in_array($decision, ['approved', 'rejected'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject and enter a reason.']);
        }
        PostingTransaction::run(function () use ($actor, $count, $decision, $reason): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'pending') {
                if ($count->status === $decision) {
                    return;
                }
                throw ValidationException::withMessages(['decision' => 'This count was already decided. Record a fresh count for further corrections.']);
            }
            if ($decision === 'approved') {
                $products = Product::query()->whereIn('id', $count->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($count->items as $item) {
                    $product = $products->get($item->product_id);
                    if ($product === null || $product->version !== $item->record_version || $product->quantity_on_hand !== $item->system_quantity || $product->inventory_value_minor !== $item->value_before_minor) {
                        throw ValidationException::withMessages(['decision' => $item->sku.' changed since counting. Reject this count and physically recount. Nothing was adjusted.']);
                    }
                    $oldQuantity = StockArithmetic::scaled($item->system_quantity);
                    $newQuantity = StockArithmetic::scaled($item->counted_quantity);
                    $oldValue = $item->value_before_minor;
                    // Preserve carried average cost; never invent a cost for found stock on an empty/unknown balance.
                    $calculated = $oldValue === null || $oldQuantity === 0 ? null : BigDecimal::of($oldValue)->multipliedBy($newQuantity)->dividedBy($oldQuantity, 0, RoundingMode::HalfUp);
                    if ($calculated?->isGreaterThan(StockArithmetic::MAX_TOTAL_MINOR)) {
                        throw ValidationException::withMessages(['decision' => 'Adjusted stock value exceeds the supported total.']);
                    }
                    $newValue = $newQuantity === 0 ? 0 : $calculated?->toInt();
                    if ($newValue !== null) {
                        StockArithmetic::total((int) Product::query()->where('id', '!=', $product->id)->sum('inventory_value_minor'), $newValue, 'decision');
                    }
                    $product->quantity_on_hand = $item->counted_quantity;
                    $product->inventory_value_minor = $newValue;
                    $product->average_cost_minor = $newValue !== null && $newQuantity > 0 ? StockArithmetic::average($newValue, $newQuantity) : null;
                    $product->version++;
                    $product->save();
                    $item->value_after_minor = $newValue;
                    $item->save();
                    DB::table('stock_movements')->insert(['product_id' => $product->id, 'stock_count_item_id' => $item->id, 'kind' => 'stock_adjustment', 'quantity_delta' => $item->quantity_delta, 'balance_after' => $item->counted_quantity, 'value_delta_minor' => $oldValue !== null && $newValue !== null ? $newValue - $oldValue : null, 'effective_on' => now('Africa/Nairobi')->toDateString(), 'actor_id' => $actor->id, 'created_at' => now()]);
                }
            }
            $count->fill(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason])->save();
            AccessAudit::record('stock_count.'.$decision, $actor, null, $reason, ['status' => 'pending'], ['status' => $decision, 'reference' => $count->reference], 'stock_count', $count->id);
        }, 'decision');
    }
}
