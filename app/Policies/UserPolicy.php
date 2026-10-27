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
     * Edit name, email (via confirmation) and private contact details.
     */
    public function update(User $actor, User $user): bool
    {
        return $this->manageSecurity($actor, $user);
    }

    public function resendVerification(User $actor, User $user): bool
    {
        return $this->manageSecurity($actor, $user);
    }

    /**
     * Delete or anonymise another account. Super admins only, and never
     * another super admin, so the last owner cannot be removed.
     */
    public function erase(User $actor, User $user): bool
    {
        return $actor->role === Role::SuperAdmin
            && ! $actor->is($user)
            && $user->role !== Role::SuperAdmin;
    }

    /**
     * In-app role changes apply only to accounts that do not sign in through
     * Cloudron SSO; directory groups stay the source of truth for SSO roles.
     */
    public function changeRole(User $actor, User $user): bool
    {
        return $actor->role === Role::SuperAdmin
            && ! $actor->is($user)
            && $user->auth_provider !== 'cloudron_oidc';
    }
}
