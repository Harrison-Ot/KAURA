<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property int $category_id
 * @property string $unit_label
 * @property int $quantity_precision
 * @property int $selling_price_minor
 * @property int|null $purchase_cost_minor
 * @property string $reorder_threshold
 * @property string $quantity_on_hand
 * @property string|null $barcode
 * @property bool $is_active
 * @property int $version
 * @property int|null $inventory_value_minor
 * @property int|null $average_cost_minor
 * @property-read Category $category
 */
class Product extends Model
{
    protected $fillable = ['sku', 'name', 'name_key', 'category_id', 'unit_label', 'quantity_precision', 'selling_price_minor', 'purchase_cost_minor', 'reorder_threshold', 'barcode', 'is_active'];

    protected $attributes = ['quantity_on_hand' => '0.000', 'version' => 1, 'is_active' => true, 'inventory_value_minor' => 0];

    protected function casts(): array
    {
        return ['selling_price_minor' => 'integer', 'purchase_cost_minor' => 'integer', 'average_cost_minor' => 'integer', 'inventory_value_minor' => 'integer', 'quantity_precision' => 'integer', 'version' => 'integer', 'is_active' => 'boolean', 'reorder_threshold' => 'decimal:3', 'quantity_on_hand' => 'decimal:3'];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
