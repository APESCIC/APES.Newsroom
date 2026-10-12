<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tracks when the current session last proved the user's identity (an
 * interactive sign-in or an explicit confirmation) so sensitive actions can
 * insist on a recent one.
 */
class RecentAuthentication
{
    public const SESSION_KEY = 'auth.reauthenticated_at';

    public const METHOD_PASSWORD = 'password';

    public const METHOD_EMAIL_CODE = 'email_code';

    public const METHOD_SSO = 'sso';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record a successful explicit identity confirmation.
     */
    public function confirm(Request $request, User $user, string $method): void
    {
        $this->stamp($request);
        $this->audit->record($user, 'account.reauthenticated', $user, ['method' => $method], $request);
    }

    public function fail(Request $request, User $user, string $method): void
    {
        $this->audit->record($user, 'account.reauthentication_failed', $user, ['method' => $method], $request);
    }

    public function stamp(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        }
    }

    public function isRecent(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $at = (int) $request->session()->get(self::SESSION_KEY, 0);

        return $at > 0 && now()->getTimestamp() - $at <= max(60, (int) config('security.reauth_timeout', 900));
    }

    /**
     * Send the user to confirm their identity, then back to where they were.
     * Non-GET actions cannot be replayed, so they return to the page that
     * submitted them.
     */
    public function challenge(Request $request): RedirectResponse
    {
        $request->session()->put(
            'url.intended',
            $request->isMethod('GET') ? $request->fullUrl() : url()->previous(route('account.show')),
        );

        return redirect()->route('account.confirm-identity');
    }

    public function methodFor(User $user): string
    {
        if ($user->auth_provider === 'cloudron_oidc' && filled(config('services.cloudron_oidc.discovery_url'))) {
            return self::METHOD_SSO;
        }

        if (filled($user->password) && $user->auth_provider !== 'cloudron_oidc') {
            return self::METHOD_PASSWORD;
        }

        return self::METHOD_EMAIL_CODE;
    }
}
