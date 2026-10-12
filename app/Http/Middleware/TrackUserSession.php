<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SessionTracker;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out sessions the user ended from the account security page, and
 * keeps "last active" current. Sessions signed in before session tracking
 * existed get a record on their next request.
 */
class TrackUserSession
{
    public function __construct(private readonly SessionTracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        if (! $this->tracker->hasKey($request)) {
            $this->tracker->start($user, $request, remembered: Auth::guard('web')->viaRemember());

            return $next($request);
        }

        $session = $this->tracker->current($request);

        if ($session === null || $session->revoked_at !== null || (int) $session->user_id !== (int) $user->getKey()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('Session ended.', ['web']);
        }

        $this->tracker->touch($session, $request);

        return $next($request);
    }
}
