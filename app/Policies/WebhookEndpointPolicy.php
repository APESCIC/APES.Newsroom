<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Webhook management is admin-only: endpoints receive signed content events
 * and their secrets authenticate the Newsroom to third parties.
 */
class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function update(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function delete(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }
}
