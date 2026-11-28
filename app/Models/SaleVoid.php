<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sale_id
 * @property int $confirmed_minor
 * @property string $stock_status
 */
class SaleVoid extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sale_id' => 'integer', 'actor_id' => 'integer', 'customer_id' => 'integer', 'confirmed_minor' => 'integer', 'canceled_debt_minor' => 'integer', 'created_at' => 'immutable_datetime', 'stock_decided_at' => 'immutable_datetime'];
    }
}
