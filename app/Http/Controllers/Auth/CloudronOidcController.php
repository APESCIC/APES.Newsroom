<?php

namespace App\Http\Controllers\Auth;

use App\Events\Auth\OidcSignInAllowed;
use App\Events\Auth\OidcSignInDenied;
use App\Exceptions\Auth\OidcValidationException;
use App\Http\Controllers\Controller;
use App\Services\Auth\CloudronOidcProvider;
use App\Services\Auth\RecentAuthentication;
use App\Services\Auth\StaffOidcIdentity;
use App\Services\Auth\StaffReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

class CloudronOidcController extends Controller
{
    public const REAUTH_SESSION_KEY = 'cloudron_oidc_reauth';

    /**
     * Allowed clock skew between Cloudron and this app when checking auth_time.
     */
    private const AUTH_TIME_LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly CloudronOidcProvider $provider,
        private readonly StaffReconciler $reconciler,
        private readonly RecentAuthentication $recentAuth,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        return $this->startAuthorization($request);
    }

    /**
     * Ask Cloudron to authenticate the signed-in staff user again now
     * (max_age=0), then return to the action that needed it.
     */
    public function reauthenticate(Request $request): RedirectResponse
    {
        $request->session()->put(self::REAUTH_SESSION_KEY, [
            'user_id' => $request->user()->id,
            'started_at' => now()->getTimestamp(),
        ]);

        return $this->startAuthorization($request, ['max_age' => 0, 'prompt' => 'login']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $reauth = $request->session()->pull(self::REAUTH_SESSION_KEY);

        if (! is_array($reauth) && $request->user()) {
            return redirect()->route('home');
        }

        $expectedState = $request->session()->pull('cloudron_oidc_state');
        $nonce = $request->session()->pull('cloudron_oidc_nonce');
        $pkceCode = $request->session()->pull('cloudron_oidc_pkce');
        $state = $request->query('state');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)
            || ! is_string($nonce) || ! is_string($pkceCode)) {
            return $this->fail($reauth, 'invalid_state', 'Staff sign-in failed: invalid or expired authentication state.');
        }

        if ($request->filled('error')) {
            return $this->fail($reauth, 'provider_refused', 'Staff sign-in was cancelled or refused by the identity provider.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->fail($reauth, 'missing_code', 'Staff sign-in failed: no authorization code returned.');
        }

        try {
            $identity = $this->provider->exchangeCodeForIdentity($code, $pkceCode, $nonce);
        } catch (OidcValidationException|IdentityProviderException $e) {
            Log::warning('auth.oidc_callback_rejected', [
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return $this->fail($reauth, 'unverifiable_response', 'Staff sign-in failed: the identity provider response could not be verified.');
        }

        if (is_array($reauth)) {
            return $this->completeReauthentication($request, $identity, $reauth);
        }

        $result = $this->reconciler->reconcile($identity);

        if (! $result->allowed) {
            return $this->fail(null, 'directory_denied', $result->denialReason ?? 'Staff sign-in failed: access denied.');
        }

        auth()->login($result->user);

        $request->session()->regenerate();

        OidcSignInAllowed::dispatch($result->user, $identity->amr);

        return redirect()->route('home');
    }

    /**
     * @param  array<string, string|int>  $extra
     */
    private function startAuthorization(Request $request, array $extra = []): RedirectResponse
    {
        $nonce = Str::random(40);
        $authorizationUrl = $this->provider->authorizationUrl($nonce, $extra);

        $request->session()->put([
            'cloudron_oidc_state' => $this->provider->getState(),
            'cloudron_oidc_nonce' => $nonce,
            'cloudron_oidc_pkce' => $this->provider->getPkceCode(),
        ]);

        return redirect()->away($authorizationUrl);
    }

    /**
     * @param  array<string, mixed>  $reauth
     */
    private function completeReauthentication(Request $request, StaffOidcIdentity $identity, array $reauth): RedirectResponse
    {
        $user = $request->user();
        $startedAt = (int) ($reauth['started_at'] ?? 0);

        $verified = $user !== null
            && $user->id === ($reauth['user_id'] ?? null)
            && is_string($user->external_id)
            && hash_equals($user->external_id, $identity->sub)
            && $identity->authTime !== null
            && $identity->authTime >= $startedAt - self::AUTH_TIME_LEEWAY_SECONDS;

        if (! $verified) {
            if ($user) {
                $this->recentAuth->fail($request, $user, RecentAuthentication::METHOD_SSO);
            }

            return redirect()->route('account.confirm-identity')
                ->withErrors(['sso' => 'We could not confirm it was you. Please sign in to APES again.']);
        }

        $this->recentAuth->confirm($request, $user, RecentAuthentication::METHOD_SSO);

        return redirect()->intended(route('account.show'));
    }

    /**
     * @param  array<string, mixed>|null  $reauth
     */
    private function fail(?array $reauth, string $reason, string $message): RedirectResponse
    {
        if (is_array($reauth)) {
            return redirect()->route('account.confirm-identity')->withErrors(['sso' => $message]);
        }

        OidcSignInDenied::dispatch($reason);

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
