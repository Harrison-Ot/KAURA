<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $product_id
 * @property int|null $customer_id
 * @property int $record_version
 * @property string $label
 * @property string|null $quantity
 * @property int|null $unit_cost_minor
 * @property int|null $amount_minor
 * @property string|null $due_on
 */
class InventoryBatchItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['record_version' => 'integer', 'quantity' => 'decimal:3', 'unit_cost_minor' => 'integer', 'amount_minor' => 'integer'];
    }
}
