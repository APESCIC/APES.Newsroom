<?php

namespace App\Services\Account;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Permanent account deletion, shared by self-service deletion and admin
 * erasure. Profile, comments, sessions and tokens cascade with the user.
 */
class AccountEraser
{
    public function __construct(
        private readonly AccountDeletionPolicy $policy,
        private readonly AccountMailingDeletion $mailingDeletion,
    ) {}

    /**
     * Delete the account unless the policy blocks it.
     *
     * @return string|null the blocking reason, or null once deleted
     */
    public function deleteIfAllowed(User $user, bool $keepSubscriptions, ?Request $request = null): ?string
    {
        return DB::transaction(function () use ($user, $keepSubscriptions, $request) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $reason = $this->policy->blockingReason($lockedUser);

            if ($reason === null) {
                $this->delete($lockedUser, $keepSubscriptions, $request);
            }

            return $reason;
        });
    }

    /**
     * Callers must hold a row lock and have checked the deletion policy.
     */
    public function delete(User $lockedUser, bool $keepSubscriptions, ?Request $request = null): void
    {
        $this->mailingDeletion->handleBeforeUserDelete($lockedUser, $keepSubscriptions, $request);
        AuditLog::query()->where('actor_id', $lockedUser->id)->selfService()->update(['actor_id' => null]);
        $lockedUser->delete();
    }
}
