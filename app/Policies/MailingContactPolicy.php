<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MailingContact;
use App\Models\Membership;
use App\Models\User;

/**
 * Staff manage mailing-only subscribers (#569). A contact linked to an
 * account follows that account: its name and email change through the
 * account, and its newsletter toggles are admin-only (#570).
 */
class MailingContactPolicy
{
    public function view(User $actor, MailingContact $contact): bool
    {
        return $actor->role->atLeast(Role::Staff);
    }

    public function update(User $actor, MailingContact $contact): bool
    {
        return $actor->role->atLeast(Role::Staff) && $contact->isMailingOnly();
    }

    public function suppress(User $actor, MailingContact $contact): bool
    {
        return $this->manageSubscriptions($actor, $contact);
    }

    public function manageSubscriptions(User $actor, MailingContact $contact): bool
    {
        if ($contact->isMailingOnly()) {
            return $actor->role->atLeast(Role::Staff);
        }

        $owner = $contact->user;

        return $owner !== null && $actor->can('manageNewsletters', [Membership::class, $owner]);
    }

    public function delete(User $actor, MailingContact $contact): bool
    {
        return $actor->role->atLeast(Role::Admin) && $contact->isMailingOnly();
    }
}
