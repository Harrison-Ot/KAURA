<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sold_by
 * @property string $reference
 * @property string $payload_hash
 * @property int $total_minor
 * @property int|null $cost_total_minor
 * @property int $cash_tendered_minor
 * @property int $change_minor
 * @property CarbonImmutable $sold_at
 * @property-read Collection<int, SaleItem> $items
 * @property-read Collection<int, Payment> $payments
 */
class Sale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sold_by' => 'integer', 'customer_id' => 'integer', 'shop_snapshot' => 'array', 'customer_snapshot' => 'array', 'gross_minor' => 'integer', 'discount_minor' => 'integer', 'total_minor' => 'integer', 'cost_total_minor' => 'integer', 'cash_tendered_minor' => 'integer', 'change_minor' => 'integer', 'sold_at' => 'immutable_datetime'];
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('position');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
