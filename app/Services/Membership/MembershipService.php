<?php

namespace App\Services\Membership;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Services\Mailing\LinkMailingContactService;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function __construct(
        private readonly OutboundWebhookDispatcher $webhooks,
        private readonly LinkMailingContactService $mailingLinks,
    ) {}

    /**
     * Ensure the user has a free membership row. Mailing contacts are linked
     * only after email ownership is verified (#506); before that, create an
     * unlinked contact for brand-new emails only and leave pre-existing
     * mailing-only contacts unclaimed.
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

            if ($user->email_verified_at !== null) {
                $this->mailingLinks->link($user);
            } else {
                $this->mailingLinks->ensureUnlinkedContactForEmail(
                    (string) $user->email,
                    $user->name !== '' ? $user->name : null,
                );
            }

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
}
