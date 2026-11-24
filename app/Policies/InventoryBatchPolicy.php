<?php

namespace App\Policies;

use App\Models\InventoryBatch;
use App\Models\User;

class InventoryBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === 'owner';
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, InventoryBatch $batch): bool
    {
        return $this->viewAny($user);
    }
}
