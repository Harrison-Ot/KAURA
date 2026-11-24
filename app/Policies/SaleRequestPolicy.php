<?php

namespace App\Policies;

use App\Models\SaleRequest;
use App\Models\User;
use App\Services\AccessAudit;

class SaleRequestPolicy
{
    public function view(User $user, SaleRequest $saleRequest): bool
    {
        $allowed = $user->is_active && ($user->role === 'owner' || $user->id === $saleRequest->requested_by);
        if (! $allowed) {
            AccessAudit::record('access.denied', $user, null, 'Sale request belongs to another employee.', null, null, 'sale_request', $saleRequest->id);
        }

        return $allowed;
    }

    public function decide(User $user, SaleRequest $saleRequest): bool
    {
        return $user->is_active && $user->role === 'owner';
    }
}
