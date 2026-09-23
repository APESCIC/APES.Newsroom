<?php

namespace App\Services\Membership;

use App\Enums\ContentVisibility;
use App\Enums\Role;
use App\Models\User;

class ContentAccessService
{
    /**
     * @return array{allowed: bool, reason: ?string, cta: ?array{label: string, href: string}}
     */
    public function evaluate(ContentVisibility $visibility, ?User $viewer): array
    {
        if ($viewer?->role->atLeast(Role::Staff)) {
            return ['allowed' => true, 'reason' => null, 'cta' => null];
        }

        return match ($visibility) {
            ContentVisibility::Public => ['allowed' => true, 'reason' => null, 'cta' => null],
            ContentVisibility::Members => $viewer
                ? ['allowed' => true, 'reason' => null, 'cta' => null]
                : [
                    'allowed' => false,
                    'reason' => 'members',
                    'cta' => [
                        'label' => 'Create a free account',
                        'href' => route('register'),
                    ],
                ],
            ContentVisibility::Paid => $this->viewerIsPaying($viewer)
                ? ['allowed' => true, 'reason' => null, 'cta' => null]
                : [
                    'allowed' => false,
                    'reason' => 'paid',
                    'cta' => [
                        'label' => $viewer ? 'Become a paid member' : 'Sign in to subscribe',
                        'href' => $viewer ? route('account.show') : route('login'),
                    ],
                ],
        };
    }

    private function viewerIsPaying(?User $viewer): bool
    {
        if (! $viewer) {
            return false;
        }

        $membership = app(MembershipService::class)->ensureMembership($viewer);

        return $membership->isPaying();
    }
}
