<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
