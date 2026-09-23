<?php

namespace App\Services\Membership;

use App\Enums\MembershipStatus;
use App\Models\MailingContact;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembershipService
{
    /**
     * Ensure the user has a free membership row and a mailing contact linked by email.
     * Does not subscribe the contact to any list or newsletter.
     */
    public function provisionFreeMember(User $user): Membership
    {
        return DB::transaction(function () use ($user) {
            $membership = $this->ensureMembership($user);
            $this->linkMailingContact($user);

            return $membership;
        });
    }

    public function ensureMembership(User $user): Membership
    {
        $membership = Membership::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => MembershipStatus::Free],
        );

        return $membership->fresh() ?? $membership;
    }

    /**
     * Link or create a mailing contact for the user's email without changing subscriptions.
     */
    public function linkMailingContact(User $user): MailingContact
    {
        $email = Str::lower(trim($user->email));

        $contact = MailingContact::query()->firstOrCreate(
            ['email' => $email],
            ['user_id' => $user->id],
        );

        if ($contact->user_id !== $user->id) {
            $contact->update(['user_id' => $user->id]);
        }

        return $contact->fresh() ?? $contact;
    }
}
