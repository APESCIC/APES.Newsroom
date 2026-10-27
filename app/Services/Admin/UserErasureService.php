<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Services\Account\AccountDeletionPolicy;
use App\Services\Account\AccountEraser;
use App\Services\Account\AccountMailingDeletion;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin-led erasure. Accounts the deletion policy allows are deleted
 * outright; accounts that own editorial or audit records are anonymised in
 * place so attribution and foreign keys survive.
 */
class UserErasureService
{
    public const MODE_DELETED = 'deleted';

    public const MODE_ANONYMISED = 'anonymised';

    public const ANONYMISED_NAME = 'Deleted user';

    public function __construct(
        private readonly AccountDeletionPolicy $policy,
        private readonly AccountEraser $eraser,
        private readonly AccountMailingDeletion $mailingDeletion,
        private readonly UserSessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Which erasure the account would get, for the confirmation screen.
     */
    public function plannedMode(User $user): string
    {
        return $this->policy->blockingReason($user) === null ? self::MODE_DELETED : self::MODE_ANONYMISED;
    }

    /**
     * @return string the erasure mode applied
     */
    public function erase(User $user, User $actor, string $reason, Request $request): string
    {
        $userId = $user->id;

        $mode = DB::transaction(function () use ($user, $actor) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($this->policy->blockingReason($lockedUser) === null) {
                $this->eraser->delete($lockedUser, false, null);

                return self::MODE_DELETED;
            }

            $this->anonymise($lockedUser, $actor);

            return self::MODE_ANONYMISED;
        });

        $this->audit->record($actor, 'user.erased', $user, [
            'reason' => $reason,
            'mode' => $mode,
            'user_id' => $userId,
        ], $request);

        return $mode;
    }

    private function anonymise(User $user, User $actor): void
    {
        $this->mailingDeletion->handleBeforeUserDelete($user, false, null);

        $user->passkeys()->delete();
        $user->apiTokens()->delete();
        $user->magicLinkTokens()->delete();
        $user->emailChangeRequests()->delete();
        $user->profile()->delete();

        $user->forceFill([
            ...User::clearedContactDetails(),
            'name' => self::ANONYMISED_NAME,
            'email' => 'erased-'.$user->id.'@users.invalid',
            'email_verified_at' => null,
            'password' => null,
            'remember_token' => Str::random(60),
            'role' => Role::Public,
            'auth_provider' => null,
            'external_id' => null,
            'ldap_group_snapshot' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
            'matched_mailing_notice_pending' => false,
            'locked_at' => now(),
            'locked_reason' => 'Account erased',
            'locked_by' => $actor->id,
        ])->save();

        $this->sessions->revoke($user);
    }
}
