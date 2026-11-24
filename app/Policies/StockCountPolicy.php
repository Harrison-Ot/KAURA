<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;
use App\Services\AccessAudit;

class StockCountPolicy
{
    public function view(User $user, StockCount $count): bool
    {
        $allowed = $user->is_active && ($user->role === 'owner' || $count->counted_by === $user->id);
        if (! $allowed) {
            AccessAudit::record('access.denied', $user, null, 'Stock count belongs to another employee.', null, null, 'stock_count', $count->id);
        }

        return $allowed;
    }

    public function decide(User $user, StockCount $count): bool
    {
        return $user->is_active && $user->role === 'owner';
    }
}
