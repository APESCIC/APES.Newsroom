<?php

namespace App\Services\Auth;

use App\Models\User;
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

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }
}
