<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use App\Services\AccessAudit;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Sale $sale): bool
    {
        $allowed = $user->is_active && ($user->role === 'owner' || $sale->sold_by === $user->id);
        if (! $allowed) {
            AccessAudit::record('access.denied', $user, null, 'Sale belongs to another employee.', null, null, 'sale', $sale->id);
        }

        return $allowed;
    }

    public function void(User $user, Sale $sale): bool
    {
        return $this->view($user, $sale);
    }
}
