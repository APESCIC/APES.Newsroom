<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Page;
use App\Models\User;

class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Staff);
    }

    /**
     * Staff only see their own pages; admins see every author's.
     */
    public function viewAll(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Staff);
    }

    /**
     * Staff may publish their own pages; pages have no review step.
     */
    public function update(User $user, Page $page): bool
    {
        if ($user->role->atLeast(Role::Admin)) {
            return true;
        }

        return $page->author_id === $user->id && $user->role->atLeast(Role::Staff);
    }

    public function delete(User $user, Page $page): bool
    {
        return $this->update($user, $page);
    }
}
