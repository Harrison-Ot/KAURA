<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sale_void_id
 * @property int $amount_minor
 * @property int $actor_id
 * @property string $payload_hash
 * @property string $kind
 */
class VoidResolution extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sale_void_id' => 'integer', 'amount_minor' => 'integer', 'actor_id' => 'integer', 'customer_id' => 'integer', 'customer_snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
