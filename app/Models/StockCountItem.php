<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property int $record_version
 * @property string $sku
 * @property string $system_quantity
 * @property string $counted_quantity
 * @property string $quantity_delta
 * @property int|null $value_before_minor
 * @property int|null $value_after_minor
 */
class StockCountItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'record_version' => 'integer', 'system_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3', 'quantity_delta' => 'decimal:3', 'value_before_minor' => 'integer', 'value_after_minor' => 'integer'];
    }
}
