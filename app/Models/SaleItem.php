<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $quantity
 * @property int $unit_price_minor
 * @property int $total_minor
 * @property int|null $unit_cost_minor
 * @property int|null $cost_total_minor
 */
class SaleItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_precision' => 'integer', 'quantity' => 'decimal:3', 'unit_price_minor' => 'integer', 'total_minor' => 'integer', 'unit_cost_minor' => 'integer', 'cost_total_minor' => 'integer'];
    }
}
