<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Admin user management. Super admins can additionally change roles and act
 * on other admins; nobody can act on an account of equal or higher rank
 * except a super admin acting on an admin.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(Role::Admin);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->role->atLeast(Role::Admin);
    }

    /**
     * Security actions (lock, sign out, reset credentials) on another account.
     */
    public function manageSecurity(User $actor, User $user): bool
    {
        if (! $actor->role->atLeast(Role::Admin) || $actor->is($user)) {
            return false;
        }

        if ($actor->role === Role::SuperAdmin) {
            return $user->role !== Role::SuperAdmin;
        }

        return $actor->role->rank() > $user->role->rank();
    }

    /**
     * In-app role changes apply only to non-SSO accounts; directory groups
     * stay the source of truth for staff roles.
     */
    public function changeRole(User $actor, User $user): bool
    {
        return $actor->role === Role::SuperAdmin
            && ! $actor->is($user)
            && ! $user->requiresSso();
    }
}
