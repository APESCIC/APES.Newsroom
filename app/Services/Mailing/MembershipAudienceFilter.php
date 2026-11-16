<?php

namespace App\Services\Mailing;

use App\Enums\ContentVisibility;
use App\Enums\MembershipStatus;
use App\Models\MailingContact;
use App\Models\Membership;
use App\Models\User;

/**
 * Filters consented newsletter emails by membership for free/paid targeting.
 *
 * Decision (#677): a free-members post emails all members (free and paid).
 * Paid-only sends go only to active paid (or comped) members.
 */
class MembershipAudienceFilter
{
    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    public function filter(array $emails, ContentVisibility $visibility): array
    {
        if ($visibility === ContentVisibility::Public) {
            return array_values($emails);
        }

        $normalized = collect($emails)
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return [];
        }

        $contacts = MailingContact::query()
            ->whereIn('email', $normalized->all())
            ->get(['id', 'email', 'user_id']);

        $userIds = $contacts->pluck('user_id')->filter()->unique()->values();
        $usersByEmail = User::query()
            ->whereIn('email', $normalized->all())
            ->get(['id', 'email'])
            ->keyBy(fn (User $user) => strtolower($user->email));

        foreach ($usersByEmail as $user) {
            $userIds->push($user->id);
        }
        $userIds = $userIds->unique()->values();

        $memberships = Membership::query()
            ->whereIn('user_id', $userIds->all())
            ->get()
            ->keyBy('user_id');

        $allowedUserIds = $memberships
            ->filter(fn (Membership $membership) => $this->membershipMatches($membership, $visibility))
            ->keys()
            ->all();

        $contactUserByEmail = $contacts->mapWithKeys(
            fn (MailingContact $contact) => [strtolower($contact->email) => $contact->user_id]
        );

        return $normalized
            ->filter(function (string $email) use ($contactUserByEmail, $usersByEmail, $allowedUserIds) {
                $userId = $contactUserByEmail->get($email) ?? $usersByEmail->get($email)?->id;

                return $userId !== null && in_array($userId, $allowedUserIds, true);
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $emails
     * @return array{total:int,included:int,excluded:int}
     */
    public function counts(array $emails, ContentVisibility $visibility): array
    {
        $included = $this->filter($emails, $visibility);

        return [
            'total' => count($emails),
            'included' => count($included),
            'excluded' => max(0, count($emails) - count($included)),
        ];
    }

    private function membershipMatches(Membership $membership, ContentVisibility $visibility): bool
    {
        return match ($visibility) {
            ContentVisibility::Public => true,
            // Free-members post: every member (free status, paying, or comped).
            ContentVisibility::Members => $membership->status === MembershipStatus::Free
                || $membership->hasPaidAccess(),
            ContentVisibility::Paid => $membership->hasPaidAccess(),
        };
    }
}
