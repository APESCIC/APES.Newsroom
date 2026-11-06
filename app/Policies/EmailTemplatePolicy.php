<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * System email templates are admin-only. Staff get 403 via access-admin-area.
 */
class EmailTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }
}
