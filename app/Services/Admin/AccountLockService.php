<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Notifications\Security\AccountLockedNotification;
use App\Notifications\Security\AccountUnlockedNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountLockService
{
    public function __construct(
        private readonly UserSessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function lock(User $user, User $actor, string $reason, Request $request): void
    {
        DB::transaction(function () use ($user, $actor, $reason) {
            LastAdminGuard::ensureAnotherAdmin($user, 'reason', 'The last unlocked admin cannot be locked.');

            $user->forceFill([
                'locked_at' => now(),
                'locked_reason' => $reason,
                'locked_by' => $actor->id,
            ])->save();
        });

        $this->sessions->revoke($user);
        $user->magicLinkTokens()->whereNull('used_at')->delete();

        $this->audit->record($actor, 'user.locked', $user, ['reason' => $reason], $request);
        $user->notify(new AccountLockedNotification);
    }

    public function unlock(User $user, User $actor, string $reason, Request $request): void
    {
        $user->forceFill([
            'locked_at' => null,
            'locked_reason' => null,
            'locked_by' => null,
        ])->save();

        $this->audit->record($actor, 'user.unlocked', $user, ['reason' => $reason], $request);
        $user->notify(new AccountUnlockedNotification);
    }
}
