<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\InventoryBatchItem;
use App\Models\Product;
use App\Models\ShopSettings;
use App\Models\User;
use App\Support\CatalogueValues;
use App\Support\MpesaCodes;
use App\Support\StockArithmetic;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /** @param array<string, mixed> $data */
    public function draft(User $actor, array $data): InventoryBatch
    {
        $this->authorize($actor);
        unset($data['csv']);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $data, $hash): InventoryBatch {
                $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
                $existing = InventoryBatch::query()->where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    if ($existing->payload_hash !== $hash) {
                        throw ValidationException::withMessages(['submission_key' => 'This submission key belongs to a different draft. Open a new draft.']);
                    }

                    return $existing;
                }
                $this->checkPhase($settings, $data['kind'], $data['effective_on']);
                $items = [];
                $total = 0;
                $complete = true;
                foreach ($data['rows'] as $index => $row) {
                    if ($data['kind'] === 'opening_debt') {
                        $customer = Customer::query()->where('id', $row['customer_id'])->firstOrFail();
                        if (DB::table('receivables')->where('opening_customer_id', $customer->id)->exists()) {
                            throw ValidationException::withMessages(['rows.'.$index.'.customer_id' => 'This customer already has an opening debt. Use a reasoned correction, not another import.']);
                        }
                        $amount = CatalogueValues::minorUnits((string) $row['amount']);
                        if ($amount === 0) {
                            throw ValidationException::withMessages(['rows.'.$index.'.amount' => 'Opening debt must be greater than zero. Omit customers without debt.']);
                        }
                        $items[] = ['position' => $index + 1, 'customer_id' => $customer->id, 'record_version' => $customer->version, 'label' => $customer->name.' · '.$customer->contact, 'amount_minor' => $amount, 'due_on' => $row['due_on']];
                    } else {
                        $product = Product::query()->where('sku', $row['sku'])->first();
                        if (! $product) {
                            throw ValidationException::withMessages(['rows.'.$index.'.sku' => 'SKU not found. Create and review the catalogue listing first.']);
                        }
                        if ($row['unit'] !== $product->unit_label) {
                            throw ValidationException::withMessages(['rows.'.$index.'.unit' => 'Unit must match '.$product->unit_label.' for '.$product->sku.'.']);
                        }
                        $quantity = StockArithmetic::quantity((string) $row['quantity'], $product->quantity_precision, 'rows.'.$index.'.quantity');
                        if ($data['kind'] === 'stock_receipt' && ($quantity === 0 || ! $product->is_active)) {
                            throw ValidationException::withMessages(['rows.'.$index.'.quantity' => 'Deliveries require active products and positive quantities.']);
                        }
                        if ($data['kind'] === 'opening_stock' && DB::table('stock_movements')->where('product_id', $product->id)->exists()) {
                            throw ValidationException::withMessages(['rows.'.$index.'.sku' => 'Opening stock was already posted for this SKU.']);
                        }
                        $cost = isset($row['unit_cost']) ? CatalogueValues::minorUnits((string) $row['unit_cost']) : null;
                        $amount = $quantity === 0 ? 0 : ($cost === null ? null : StockArithmetic::lineValue($quantity, $cost));
                        $items[] = ['position' => $index + 1, 'product_id' => $product->id, 'record_version' => $product->version, 'label' => $product->name, 'sku' => $product->sku, 'unit_label' => $product->unit_label, 'quantity' => StockArithmetic::decimal($quantity), 'unit_cost_minor' => $cost, 'amount_minor' => $amount];
                    }
                    $complete = $complete && $amount !== null;
                    $total = StockArithmetic::total($total, $amount ?? 0);
                }
                $supplierId = null;
                if ($data['kind'] === 'stock_receipt') {
                    if (StockArithmetic::totalAmount((string) $data['amount_paid'], 'amount_paid') !== $total) {
                        throw ValidationException::withMessages(['amount_paid' => 'Supplier deliveries must be paid in full. Enter the reviewed line total: KES '.CatalogueValues::money($total).'.']);
                    }
                    $key = CatalogueValues::nameKey($data['supplier_name']);
                    $supplierId = DB::table('suppliers')->where('name_key', $key)->value('id');
                    if ($supplierId === null) {
                        $supplierId = DB::table('suppliers')->insertGetId(['name' => $data['supplier_name'], 'name_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                        AccessAudit::record('supplier.created', $actor, null, 'Supplier added during delivery review.', null, ['name' => $data['supplier_name']], 'supplier', $supplierId);
                    }
                }
                $batch = InventoryBatch::create([
                    'reference' => strtoupper(str_replace('_', '-', $data['kind'])).'-'.Str::ulid(),
                    'submission_key' => $data['submission_key'], 'payload_hash' => $hash,
                    'kind' => $data['kind'], 'status' => 'draft', 'effective_on' => $data['effective_on'], 'source_note' => $data['source_note'],
                    'supplier_id' => $supplierId, 'supplier_name' => $data['supplier_name'] ?? null, 'delivery_reference' => $data['delivery_reference'] ?? null,
                    'delivery_key' => $supplierId === null ? null : hash('sha256', $supplierId.'|'.$data['delivery_reference']),
                    'payment_method' => $data['payment_method'] ?? null, 'payment_reference' => $data['payment_reference'] ?? null,
                    'amount_paid_minor' => $data['kind'] === 'stock_receipt' ? $total : null, 'total_minor' => $complete ? $total : null, 'created_by' => $actor->id,
                ]);
                foreach ($items as $item) {
                    $batch->items()->create($item);
                }
                AccessAudit::record('inventory.draft_created', $actor, null, $batch->source_note, null, $batch->only(['reference', 'kind', 'status', 'total_minor']), 'inventory_batch', $batch->id);

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['delivery_reference' => 'This supplier delivery reference or submission was already used. Open the existing record.']);
        }
    }

    public function post(User $actor, InventoryBatch $batch, string $reason): InventoryBatch
    {
        $this->authorize($actor);
        try {
            return DB::transaction(function () use ($actor, $batch, $reason): InventoryBatch {
                $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
                $batch = InventoryBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($batch->status === 'posted') {
                    return $batch;
                }
                if ($batch->status !== 'draft') {
                    throw ValidationException::withMessages(['batch' => 'Cancelled batches cannot be posted.']);
                }
                $this->checkPhase($settings, $batch->kind, $batch->effective_on);
                foreach ($batch->items as $item) {
                    if ($batch->kind === 'opening_debt') {
                        $this->postDebt($actor, $batch, $item);
                    } else {
                        $this->postStock($actor, $batch, $item);
                    }
                }
                if ($batch->kind === 'stock_receipt') {
                    if ($batch->payment_method === 'mpesa' && MpesaCodes::exists($batch->payment_reference)) {
                        throw ValidationException::withMessages(['batch' => 'This M-Pesa code was already recorded as an incoming payment. Nothing was posted.']);
                    }
                    DB::table('cash_outflows')->insert([
                        'batch_id' => $batch->id, 'kind' => 'stock_purchase', 'payment_method' => $batch->payment_method,
                        'payment_reference' => $batch->payment_reference, 'amount_minor' => $batch->amount_paid_minor,
                        'effective_on' => $batch->effective_on, 'actor_id' => $actor->id, 'created_at' => now(),
                    ]);
                } elseif ($settings->opening_as_of === null) {
                    $settings->opening_as_of = $batch->effective_on;
                    $settings->version++;
                    $settings->save();
                }
                $batch->fill(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now(), 'decision_reason' => $reason])->save();
                AccessAudit::record('inventory.posted', $actor, null, $reason, ['status' => 'draft'], $batch->only(['reference', 'kind', 'status', 'total_minor', 'posted_by', 'posted_at']), 'inventory_batch', $batch->id);

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['batch' => 'An opening balance or outgoing M-Pesa reference was already posted. No part of this batch was applied.']);
        }
    }

    public function cancel(User $actor, InventoryBatch $batch, string $reason): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $batch, $reason): void {
            ShopSettings::query()->lockForUpdate()->findOrFail(1);
            $batch = InventoryBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'cancelled') {
                return;
            }
            if ($batch->status !== 'draft') {
                throw ValidationException::withMessages(['batch' => 'Posted records cannot be cancelled. They require a linked correction.']);
            }
            $batch->fill(['status' => 'cancelled', 'decision_reason' => $reason, 'delivery_key' => null])->save();
            AccessAudit::record('inventory.draft_cancelled', $actor, null, $reason, ['status' => 'draft'], ['status' => 'cancelled'], 'inventory_batch', $batch->id);
        });
    }

    public function completeOpening(User $actor, string $expectedDebt, string $reason, string $effectiveOn): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($actor, $expectedDebt, $reason, $effectiveOn): void {
            $settings = ShopSettings::query()->lockForUpdate()->findOrFail(1);
            if ($settings->opening_completed_at !== null) {
                return;
            }
            if (! $settings->shop_name || InventoryBatch::query()->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['opening' => 'Enter the shop name and post or cancel every draft before signing off.']);
            }
            $missing = Product::query()->where('is_active', true)->whereNotIn('id', DB::table('stock_movements')->whereNotNull('opening_product_id')->select('opening_product_id'))->count();
            if ($missing !== 0) {
                throw ValidationException::withMessages(['opening' => $missing.' active product listings still need opening counts (including explicit zero counts).']);
            }
            $debt = (int) DB::table('receivables')->where('origin', 'opening_balance')->sum('original_amount_minor');
            if (StockArithmetic::totalAmount($expectedDebt, 'expected_debt_total') !== $debt) {
                throw ValidationException::withMessages(['expected_debt_total' => 'The paper debt total does not match the posted opening debts.']);
            }
            if ($settings->opening_as_of !== null && $settings->opening_as_of !== $effectiveOn) {
                throw ValidationException::withMessages(['effective_on' => 'The sign-off date must match the opening batches: '.$settings->opening_as_of]);
            }
            $settings->fill(['opening_as_of' => $effectiveOn, 'opening_completed_at' => now(), 'opening_completed_by' => $actor->id]);
            $settings->version++;
            $settings->save();
            AccessAudit::record('opening.signed_off', $actor, null, $reason, null, [
                'as_of' => $effectiveOn, 'opening_debt_minor' => $debt,
                'stock_listings' => DB::table('stock_movements')->where('kind', 'opening_stock')->count(),
                'known_stock_value_minor' => (int) Product::query()->sum('inventory_value_minor'),
                'unknown_cost_listings' => Product::query()->whereNull('inventory_value_minor')->count(),
            ], 'shop_settings', 1);
        });
    }

    private function postStock(User $actor, InventoryBatch $batch, InventoryBatchItem $item): void
    {
        $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
        $opening = $batch->kind === 'opening_stock';
        if ($opening && ($product->version !== $item->record_version || DB::table('stock_movements')->where('product_id', $product->id)->exists() || StockArithmetic::scaled($product->quantity_on_hand) !== 0)) {
            throw ValidationException::withMessages(['batch' => 'The count for '.$product->sku.' is stale or was already posted. Cancel and review a new draft.']);
        }
        if (! $opening && ! $product->is_active) {
            throw ValidationException::withMessages(['batch' => $product->sku.' is inactive. Nothing was posted.']);
        }
        $quantity = StockArithmetic::scaled((string) $item->quantity);
        $newQuantity = StockArithmetic::scaled($product->quantity_on_hand) + $quantity;
        if ($newQuantity > 999_999_999_999) {
            throw ValidationException::withMessages(['batch' => 'Stock quantity would exceed the supported balance for '.$product->sku.'.']);
        }
        $oldValue = $product->inventory_value_minor;
        $newValue = $oldValue === null || $item->amount_minor === null ? null : StockArithmetic::total($oldValue, $item->amount_minor);
        if ($newValue !== null) {
            $otherValue = (int) Product::query()->where('id', '!=', $product->id)->sum('inventory_value_minor');
            StockArithmetic::total($otherValue, $newValue);
        }
        $product->quantity_on_hand = StockArithmetic::decimal($newQuantity);
        $product->inventory_value_minor = $newValue;
        $product->average_cost_minor = $newValue !== null && $newQuantity > 0 ? StockArithmetic::average($newValue, $newQuantity) : null;
        $product->version++;
        $product->save();
        DB::table('stock_movements')->insert([
            'product_id' => $product->id, 'batch_item_id' => $item->id, 'opening_product_id' => $opening ? $product->id : null, 'kind' => $batch->kind,
            'quantity_delta' => $item->quantity, 'balance_after' => $product->quantity_on_hand, 'value_delta_minor' => $item->amount_minor,
            'effective_on' => $batch->effective_on, 'actor_id' => $actor->id, 'created_at' => now(),
        ]);
    }

    private function postDebt(User $actor, InventoryBatch $batch, InventoryBatchItem $item): void
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($item->customer_id);
        if ($customer->version !== $item->record_version) {
            throw ValidationException::withMessages(['batch' => 'Customer details changed after review. Cancel this draft and review the customer again.']);
        }
        StockArithmetic::total((int) DB::table('receivables')->sum('remaining_amount_minor'), (int) $item->amount_minor);
        DB::table('receivables')->insert([
            'customer_id' => $customer->id, 'opening_customer_id' => $customer->id, 'batch_item_id' => $item->id, 'origin' => 'opening_balance',
            'original_amount_minor' => $item->amount_minor, 'remaining_amount_minor' => $item->amount_minor,
            'originated_on' => $batch->effective_on, 'due_on' => $item->due_on, 'source_note' => $batch->source_note, 'actor_id' => $actor->id, 'created_at' => now(),
        ]);
        $customer->version++;
        $customer->save();
    }

    private function checkPhase(ShopSettings $settings, string $kind, string $date): void
    {
        if ($kind !== 'stock_receipt') {
            if ($settings->opening_completed_at !== null) {
                throw ValidationException::withMessages(['batch' => 'Opening balances are signed off. Later corrections require adjustment records.']);
            }
            if ($settings->opening_as_of !== null && $settings->opening_as_of !== $date) {
                throw ValidationException::withMessages(['effective_on' => 'Use the shared opening as-of date: '.$settings->opening_as_of]);
            }
        } elseif ($settings->opening_completed_at === null || $date < $settings->opening_as_of) {
            throw ValidationException::withMessages(['batch' => 'Sign off opening balances first; deliveries cannot predate the opening date.']);
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->role === 'owner', 403);
    }
}
