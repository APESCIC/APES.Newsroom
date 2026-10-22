<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Notifications\Security\AdminAccountActionNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactor\TwoFactorService;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Actions an admin can take on a compromised account. Each one is audited
 * with the admin's reason and the account holder is told by email.
 */
class AdminSecurityActions
{
    public function __construct(
        private readonly UserSessionRevoker $sessions,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function signOutEverywhere(User $user, User $actor, string $reason, Request $request): void
    {
        $this->sessions->revoke($user);

        $this->finish($user, $actor, 'user.signed_out', AdminAccountActionNotification::SIGNED_OUT, ['reason' => $reason], $request);
    }

    public function forcePasswordReset(User $user, User $actor, string $reason, Request $request): void
    {
        $user->forceFill(['password' => null])->save();
        $this->sessions->revoke($user);
        Password::broker()->sendResetLink(['email' => $user->email]);

        $this->finish($user, $actor, 'user.password_reset_forced', AdminAccountActionNotification::PASSWORD_RESET, ['reason' => $reason], $request);
    }

    public function resetTwoFactor(User $user, User $actor, string $reason, Request $request): void
    {
        $this->twoFactor->disable($user);

        $this->finish($user, $actor, 'user.two_factor_reset', AdminAccountActionNotification::TWO_FACTOR_RESET, ['reason' => $reason], $request);
    }

    public function removePasskeys(User $user, User $actor, string $reason, Request $request): int
    {
        $removed = $user->passkeys()->delete();

        $this->finish($user, $actor, 'user.passkeys_removed', AdminAccountActionNotification::PASSKEYS_REMOVED, [
            'reason' => $reason,
            'count' => $removed,
        ], $request);

        return $removed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(User $user, User $actor, string $action, string $notification, array $payload, Request $request): void
    {
        $this->audit->record($actor, $action, $user, $payload, $request);
        $user->notify(new AdminAccountActionNotification($notification));
    }
}
