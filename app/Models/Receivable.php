<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $sale_id
 * @property int $remaining_amount_minor
 */
class Receivable extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_id' => 'integer', 'sale_id' => 'integer', 'original_amount_minor' => 'integer', 'remaining_amount_minor' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
