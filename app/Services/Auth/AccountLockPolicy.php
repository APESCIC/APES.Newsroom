<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * Refuses every sign-in flow for accounts an admin has locked.
 *
 * Callers must respond exactly as they would for an unknown email or a bad
 * credential, so the refusal does not confirm the account exists.
 */
class AccountLockPolicy
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function refuses(?User $user, string $flow, Request $request): bool
    {
        if (! $user?->isLocked()) {
            return false;
        }

        $this->audit->record(null, 'auth.locked_refused', $user, ['flow' => $flow], $request);

        return true;
    }
}
