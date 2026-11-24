<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $actor_id
 * @property int|null $batch_id
 * @property int $amount_minor
 * @property string $kind
 * @property string $payment_method
 * @property string|null $payload_hash
 */
class CashOutflow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'batch_id' => 'integer', 'amount_minor' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
