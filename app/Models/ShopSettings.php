<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $shop_name
 * @property string|null $mpesa_number
 * @property int $version
 * @property string|null $opening_as_of
 * @property CarbonImmutable|null $opening_completed_at
 */
class ShopSettings extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'opening_completed_at' => 'immutable_datetime'];
    }
}
