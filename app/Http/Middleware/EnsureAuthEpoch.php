<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out sessions started before the user's sessions were revoked
 * (see UserSessionRevoker). AuthenticateSession cannot do this for
 * Cloudron SSO accounts because they have no password hash.
 */
class EnsureAuthEpoch
{
    public const SESSION_KEY = 'auth_epoch';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $current = (int) $user->auth_epoch;
        $stored = $request->session()->get(self::SESSION_KEY);

        if ($stored === null) {
            $request->session()->put(self::SESSION_KEY, $current);

            return $next($request);
        }

        if ((int) $stored !== $current) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('Session revoked.', ['web']);
        }

        return $next($request);
    }
}
