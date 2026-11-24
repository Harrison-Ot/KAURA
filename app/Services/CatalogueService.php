<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Support\CatalogueValues;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CatalogueService
{
    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?Product $product = null): Product
    {
        Gate::forUser($actor)->authorize($product ? 'update' : 'create', $product ?? Product::class);
        try {
            return DB::transaction(function () use ($actor, $data, $product): Product {
                $creating = $product === null;
                $product = $creating ? new Product : Product::query()->lockForUpdate()->findOrFail($product->id);
                if (! $creating && $product->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'This listing changed in another session. Reload it before editing again.']);
                }
                $nameKey = CatalogueValues::nameKey((string) $data['name']);
                $matches = Product::query()->where('name_key', $nameKey)->when(! $creating, fn ($query) => $query->where('id', '!=', $product->id))->limit(3)->get(['sku', 'unit_label']);
                if ($matches->isNotEmpty() && empty($data['confirm_duplicate']) && ($creating || $product->name !== $data['name'])) {
                    $labels = $matches->map(fn (Product $match): string => $match->sku.' ('.$match->unit_label.')')->implode(', ');
                    throw ValidationException::withMessages(['confirm_duplicate' => 'Similar product name already exists: '.$labels.'. Review the listings, then confirm if this is a separate sellable unit.']);
                }
                $before = $creating ? null : $product->toArray();
                $product->fill([
                    'sku' => $data['sku'], 'name' => $data['name'], 'name_key' => $nameKey,
                    'category_id' => $data['category_id'], 'unit_label' => $data['unit_label'],
                    'quantity_precision' => $data['quantity_precision'],
                    'selling_price_minor' => CatalogueValues::minorUnits((string) $data['selling_price']),
                    'purchase_cost_minor' => isset($data['purchase_cost']) ? CatalogueValues::minorUnits((string) $data['purchase_cost']) : null,
                    'reorder_threshold' => $data['reorder_threshold'], 'barcode' => $data['barcode'] ?? null,
                    'is_active' => (bool) $data['is_active'],
                ]);
                $priceChanged = $creating || $product->isDirty(['selling_price_minor', 'purchase_cost_minor']);
                if (! $creating) {
                    $product->version++;
                }
                $product->save();
                $reason = $creating ? 'Owner created product listing with zero stock.' : (string) $data['reason'];
                if ($priceChanged) {
                    DB::table('product_price_history')->insert([
                        'product_id' => $product->id, 'actor_id' => $actor->id,
                        'selling_price_minor' => $product->selling_price_minor, 'purchase_cost_minor' => $product->purchase_cost_minor,
                        'reason' => $reason, 'effective_at' => now(),
                    ]);
                }
                AccessAudit::record($creating ? 'product.created' : 'product.updated', $actor, null, $reason, $before, $product->toArray(), 'product', $product->id);

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'The SKU or barcode was just used by another listing. Review and retry.']);
        }
    }
}
