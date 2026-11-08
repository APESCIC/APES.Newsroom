<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Newsletter email templates are edited by staff and admins. Members and
 * subscribers get 403.
 */
class NewsletterTemplatePolicy
{
    public function manage(User $user): bool
    {
        return $user->role->atLeast(Role::Staff);
    }
}
