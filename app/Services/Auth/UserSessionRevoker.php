<?php

namespace App\Services\Auth;

use App\Http\Middleware\EnsureAuthEpoch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends every existing session and "remember me" cookie for a user.
 *
 * Redis sessions cannot be looked up by user, so revocation bumps
 * users.auth_epoch; EnsureAuthEpoch logs out any session that was
 * started under an older epoch. Database sessions are also deleted
 * outright.
 */
class UserSessionRevoker
{
    public function revoke(User $user): void
    {
        $user->increment('auth_epoch', 1, ['remember_token' => Str::random(60)]);

        $this->deleteDatabaseSessions($user);
    }

    /**
     * End every other session while keeping the one making this request.
     * The current device's "remember me" cookie is invalidated too; it stays
     * signed in for the rest of its session.
     */
    public function revokeOthers(User $user, Request $request): void
    {
        $user->increment('auth_epoch', 1, ['remember_token' => Str::random(60)]);

        $request->session()->put(EnsureAuthEpoch::SESSION_KEY, (int) $user->auth_epoch);

        $this->deleteDatabaseSessions($user, except: $request->session()->getId());
    }

    private function deleteDatabaseSessions(User $user, ?string $except = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }
}
