<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $handled_by
 * @property int $amount_minor
 * @property int|null $sale_id
 * @property string $method
 * @property string $status
 * @property string|null $mpesa_code
 * @property string|null $receiving_number
 * @property string $purpose
 * @property int|null $customer_id
 */
class Payment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['handled_by' => 'integer', 'sale_id' => 'integer', 'customer_id' => 'integer', 'amount_minor' => 'integer', 'received_at' => 'immutable_datetime', 'allocation_notes' => 'array', 'receipt_snapshot' => 'array'];
    }
}
