<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Staff);
    }

    /**
     * Staff only see their own posts; admins see every author's.
     */
    public function viewAll(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Staff);
    }

    public function update(User $user, Post $post): bool
    {
        if ($user->role->atLeast(Role::Admin)) {
            return true;
        }

        return $post->author_id === $user->id && $user->role->atLeast(Role::Staff);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }

    public function review(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    /**
     * Covers publish, schedule, unpublish and campaign sends.
     */
    public function publish(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }
}
