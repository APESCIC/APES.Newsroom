<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Refuses non-SSO sign-in flows (magic link, password, password reset)
 * for accounts that must authenticate through Cloudron SSO.
 *
 * Callers must respond exactly as they would for an unknown email so the
 * refusal does not reveal that an address belongs to a staff account.
 */
class SsoOnlyPolicy
{
    public function refuses(?User $user, string $flow, Request $request): bool
    {
        if (! $user?->requiresSso()) {
            return false;
        }

        Log::warning('auth.sso_required_refused', [
            'user_id' => $user->id,
            'flow' => $flow,
            'ip' => $request->ip(),
        ]);

        return true;
    }
}
