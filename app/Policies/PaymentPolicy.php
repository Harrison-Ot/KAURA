<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Services\AccessAudit;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Payment $payment): bool
    {
        $allowed = $user->is_active && ($user->role === 'owner' || $payment->handled_by === $user->id);
        if (! $allowed) {
            AccessAudit::record('access.denied', $user, null, 'Payment belongs to another employee.', null, null, 'payment', $payment->id);
        }

        return $allowed;
    }

    public function decide(User $user, Payment $payment): bool
    {
        $allowed = $user->is_active && $user->role === 'owner' && $payment->method === 'manual_mpesa';
        if (! $allowed) {
            AccessAudit::record('access.denied', $user, null, 'Only the owner may decide manual M-Pesa verification.', null, null, 'payment', $payment->id);
        }

        return $allowed;
    }
}
