<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * The member CRM exposes member names, emails and billing state, so it is
 * admin-only.
 */
class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }
}
