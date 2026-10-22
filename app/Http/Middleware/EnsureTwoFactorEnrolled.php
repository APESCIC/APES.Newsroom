<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\TwoFactorRequirement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends signed-in users whose role must use two-factor sign-in to the
 * account security page until they enrol an authenticator app or passkey.
 */
class EnsureTwoFactorEnrolled
{
    /**
     * Routes that stay reachable while enrolment is outstanding.
     */
    private const ALLOWED_ROUTES = [
        'account.security',
        'account.two-factor.*',
        'account.passkeys.*',
        'account.confirm-identity',
        'account.confirm-identity.*',
        'account.sessions.*',
        'account.email.*',
        'verification.*',
        'logout',
        'health',
    ];

    public function __construct(private readonly TwoFactorRequirement $requirement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $request->routeIs(...self::ALLOWED_ROUTES) || ! $this->requirement->isUnmet($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Set up two-factor sign-in to continue.'], 403);
        }

        return redirect()->route('account.security')->with('status', 'two-factor-required');
    }
}
