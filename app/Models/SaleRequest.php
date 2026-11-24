<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $requested_by
 * @property int|null $customer_id
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<int, array<string, mixed>> $items
 * @property string $cart_hash
 * @property string $payload_hash
 * @property string $status
 * @property string $payment_method
 * @property int $gross_minor
 * @property int $discount_minor
 * @property int $credit_minor
 * @property string|null $due_on
 * @property CarbonImmutable|null $expires_at
 * @property int|null $consumed_sale_id
 */
class SaleRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requested_by' => 'integer', 'customer_id' => 'integer', 'consumed_sale_id' => 'integer', 'items' => 'array', 'customer_snapshot' => 'array', 'gross_minor' => 'integer', 'discount_minor' => 'integer', 'credit_minor' => 'integer', 'expires_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }
}
