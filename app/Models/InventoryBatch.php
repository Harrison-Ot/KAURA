<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $reference
 * @property string $submission_key
 * @property string $payload_hash
 * @property string $kind
 * @property string $status
 * @property string $effective_on
 * @property string $source_note
 * @property int|null $total_minor
 * @property int|null $amount_paid_minor
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property-read Collection<int, InventoryBatchItem> $items
 */
class InventoryBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total_minor' => 'integer', 'amount_paid_minor' => 'integer', 'posted_at' => 'immutable_datetime'];
    }

    /** @return HasMany<InventoryBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryBatchItem::class, 'batch_id')->orderBy('position');
    }
}
