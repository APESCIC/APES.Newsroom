<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Finishes a first-factor sign-in (password or magic link). Accounts with
 * two-factor enabled are held in an unauthenticated pending state until a
 * second factor is given, so no first factor can bypass the challenge.
 */
class PendingTwoFactorLogin
{
    public const SESSION_KEY = 'login.two_factor';

    public const MAX_ATTEMPTS = 5;

    private const TTL_SECONDS = 300;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Sign the user in, or start the second-factor challenge when they have
     * two-factor enabled.
     *
     * @param  string  $via  first factor used: 'password' or 'magic_link'
     */
    public function signIn(Request $request, User $user, bool $remember, string $via, string $destination): RedirectResponse
    {
        if (! $user->hasTwoFactorEnabled()) {
            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();

            return redirect()->intended($destination);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'remember' => $remember,
            'via' => $via,
            'expires_at' => now()->getTimestamp() + self::TTL_SECONDS,
            'attempts' => 0,
        ]);

        $this->audit->record($user, 'auth.two_factor_challenged', $user, ['via' => $via], $request);

        return redirect()->route('two-factor.challenge');
    }

    /**
     * The user waiting for a second factor, if the challenge is still valid.
     */
    public function user(Request $request): ?User
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return null;
        }

        $user = User::find($pending['user_id']);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $this->clear($request);

            return null;
        }

        return $user;
    }

    /**
     * Record a wrong second factor. After too many the challenge is
     * abandoned and the user must start signing in again.
     *
     * @return bool true when the challenge has been abandoned
     */
    public function fail(Request $request, User $user, string $method): bool
    {
        $pending = $this->pending($request);
        $attempts = ($pending['attempts'] ?? 0) + 1;

        $this->audit->record($user, 'auth.two_factor_failed', $user, ['method' => $method, 'attempt' => $attempts], $request);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->clear($request);

            return true;
        }

        $request->session()->put(self::SESSION_KEY.'.attempts', $attempts);

        return false;
    }

    /**
     * Second factor accepted: sign the pending user in.
     */
    public function complete(Request $request, User $user, string $method): RedirectResponse
    {
        $pending = $this->pending($request);
        $this->clear($request);

        Auth::guard('web')->login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();

        $this->audit->record($user, 'auth.two_factor_passed', $user, [
            'method' => $method,
            'via' => $pending['via'] ?? null,
        ], $request);

        return redirect()->intended(route('home'));
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array{user_id: int, remember: bool, via: string, expires_at: int, attempts: int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['user_id'], $pending['expires_at'])) {
            return null;
        }

        if ((int) $pending['expires_at'] < now()->getTimestamp()) {
            $this->clear($request);

            return null;
        }

        return $pending;
    }
}
