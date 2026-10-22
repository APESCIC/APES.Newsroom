<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function revoke(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }
}
