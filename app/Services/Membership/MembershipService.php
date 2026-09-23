<?php

namespace App\Services\Membership;

use App\Enums\MembershipStatus;
use App\Models\MailingContact;
use App\Models\Membership;
use App\Models\User;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembershipService
{
    public function __construct(private readonly OutboundWebhookDispatcher $webhooks) {}

    /**
     * Ensure the user has a free membership row and a mailing contact linked by email.
     * Does not subscribe the contact to any list or newsletter.
     */
    public function provisionFreeMember(User $user): Membership
    {
        $created = false;

        $membership = DB::transaction(function () use ($user, &$created) {
            $membership = Membership::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['status' => MembershipStatus::Free],
            );
            $created = $membership->wasRecentlyCreated;
            $this->linkMailingContact($user);

            return $membership->fresh() ?? $membership;
        });

        if ($created) {
            $this->webhooks->dispatch(OutboundWebhookDispatcher::EVENT_MEMBER_CREATED, [
                'user_id' => $user->id,
                'email' => $user->email,
                'membership_status' => $membership->status->value,
            ]);
        }

        return $membership;
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
