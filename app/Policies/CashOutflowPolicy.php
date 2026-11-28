<?php

namespace App\Policies;

use App\Models\CashOutflow;
use App\Models\User;
use App\Services\AccessAudit;

class CashOutflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === 'owner';
    }

    public function create(User $user): bool
    {
        $allowed = $this->viewAny($user);
        if (! $allowed) {
            AccessAudit::record('access.denied', $user, null, 'Only the owner may record expenses or withdrawals.');
        }

        return $allowed;
    }

    public function view(User $user, CashOutflow $outflow): bool
    {
        return $this->viewAny($user);
    }
}
