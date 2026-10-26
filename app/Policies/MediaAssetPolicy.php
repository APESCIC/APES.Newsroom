<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MediaAsset;
use App\Models\User;

class MediaAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Staff);
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        return $user->role->atLeast(Role::Staff);
    }

    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Staff);
    }

    public function delete(User $user, MediaAsset $asset): bool
    {
        return $user->role->atLeast(Role::Staff);
    }
}
