<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * The member CRM list exposes billing state across all members, so it is
 * admin-only. Staff see one member's membership on the person page (#567);
 * complimentary access, the internal note and newsletter toggles stay with
 * admins.
 */
class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function view(User $actor, User $member): bool
    {
        return $actor->can('view', $member);
    }

    public function manageComp(User $actor, User $member): bool
    {
        return $this->adminOver($actor, $member);
    }

    public function editNote(User $actor, User $member): bool
    {
        return $this->adminOver($actor, $member);
    }

    public function manageNewsletters(User $actor, User $member): bool
    {
        return $this->adminOver($actor, $member);
    }

    private function adminOver(User $actor, User $member): bool
    {
        return $actor->role->atLeast(Role::Admin) && $actor->can('update', $member);
    }
}
