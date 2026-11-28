<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $counted_by
 * @property string $status
 * @property string $payload_hash
 * @property string $reference
 * @property string $reason
 */
class StockCount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counted_by' => 'integer', 'decided_by' => 'integer', 'counted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    /** @return HasMany<StockCountItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class)->orderBy('position');
    }
}
