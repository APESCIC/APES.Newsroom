<?php

namespace App\Policies;

use App\Models\Profile;
use App\Models\User;

/**
 * Editing or resetting public profile fields follows the same rank rule as
 * editing the owner's account details (#572).
 */
class ProfilePolicy
{
    public function updateFields(User $actor, Profile $profile): bool
    {
        $owner = $profile->user;

        return $owner !== null && $actor->can('update', $owner);
    }

    public function resetFields(User $actor, Profile $profile): bool
    {
        return $this->updateFields($actor, $profile);
    }
}
