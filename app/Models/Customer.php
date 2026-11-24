<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $contact
 * @property int $credit_limit_minor
 * @property int $version
 */
class Customer extends Model
{
    protected $fillable = ['name', 'name_key', 'contact', 'contact_key'];

    protected $attributes = ['credit_limit_minor' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['credit_limit_minor' => 'integer', 'version' => 'integer'];
    }
}
