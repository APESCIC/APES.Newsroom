<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * User management. Staff can view and edit the details of public accounts
 * (#567); security actions stay with admins. Admins can act on any other
 * account, including other admins (the routes require a recent sign-in),
 * but never on themselves and never on the last admin.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(Role::Admin);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->role->atLeast(Role::Admin)
            || ($actor->role === Role::Staff && $user->role === Role::Public);
    }

    /**
     * Security actions (lock, sign out, reset credentials) on another account.
     */
    public function manageSecurity(User $actor, User $user): bool
    {
        return $actor->role->atLeast(Role::Admin) && $this->outranks($actor, $user);
    }

    public function lock(User $actor, User $user): bool
    {
        return $this->manageSecurity($actor, $user) && ! $this->isLastAdmin($user);
    }

    /**
     * Edit name, email (via confirmation) and private contact details.
     */
    public function update(User $actor, User $user): bool
    {
        return $actor->role->atLeast(Role::Staff) && $this->outranks($actor, $user);
    }

    public function resendVerification(User $actor, User $user): bool
    {
        return $this->manageSecurity($actor, $user);
    }

    /**
     * Delete or anonymise another account.
     */
    public function erase(User $actor, User $user): bool
    {
        return $actor->role->atLeast(Role::Admin)
            && ! $actor->is($user)
            && ! $this->isLastAdmin($user);
    }

    /**
     * In-app role changes apply only to accounts that do not sign in through
     * Cloudron SSO; directory groups stay the source of truth for SSO roles.
     */
    public function changeRole(User $actor, User $user): bool
    {
        return $actor->role->atLeast(Role::Admin)
            && ! $actor->is($user)
            && $user->auth_provider !== 'cloudron_oidc'
            && ! $this->isLastAdmin($user);
    }

    private function outranks(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return false;
        }

        if ($actor->role === Role::Admin) {
            return true;
        }

        return $actor->role->rank() > $user->role->rank();
    }

    /**
     * The only admin whose account is not locked.
     */
    private function isLastAdmin(User $user): bool
    {
        return $user->role === Role::Admin
            && ! User::query()
                ->where('role', Role::Admin)
                ->whereNull('locked_at')
                ->whereKeyNot($user->getKey())
                ->exists();
    }
}
