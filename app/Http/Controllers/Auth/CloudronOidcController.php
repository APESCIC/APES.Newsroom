<?php

namespace App\Http\Controllers\Auth;

use App\Events\Auth\OidcSignInAllowed;
use App\Events\Auth\OidcSignInDenied;
use App\Exceptions\Auth\OidcValidationException;
use App\Http\Controllers\Controller;
use App\Services\Auth\CloudronOidcProvider;
use App\Services\Auth\StaffReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

class CloudronOidcController extends Controller
{
    public function __construct(
        private readonly CloudronOidcProvider $provider,
        private readonly StaffReconciler $reconciler,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $nonce = Str::random(40);
        $authorizationUrl = $this->provider->authorizationUrl($nonce);

        $request->session()->put([
            'cloudron_oidc_state' => $this->provider->getState(),
            'cloudron_oidc_nonce' => $nonce,
            'cloudron_oidc_pkce' => $this->provider->getPkceCode(),
        ]);

        return redirect()->away($authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('cloudron_oidc_state');
        $nonce = $request->session()->pull('cloudron_oidc_nonce');
        $pkceCode = $request->session()->pull('cloudron_oidc_pkce');
        $state = $request->query('state');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)
            || ! is_string($nonce) || ! is_string($pkceCode)) {
            return $this->fail('invalid_state', 'Staff sign-in failed: invalid or expired authentication state.');
        }

        if ($request->filled('error')) {
            return $this->fail('provider_refused', 'Staff sign-in was cancelled or refused by the identity provider.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->fail('missing_code', 'Staff sign-in failed: no authorization code returned.');
        }

        try {
            $identity = $this->provider->exchangeCodeForIdentity($code, $pkceCode, $nonce);
        } catch (OidcValidationException|IdentityProviderException $e) {
            Log::warning('auth.oidc_callback_rejected', [
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return $this->fail('unverifiable_response', 'Staff sign-in failed: the identity provider response could not be verified.');
        }

        $result = $this->reconciler->reconcile($identity);

        if (! $result->allowed) {
            return $this->fail('directory_denied', $result->denialReason ?? 'Staff sign-in failed: access denied.');
        }

        auth()->login($result->user);

        $request->session()->regenerate();

        OidcSignInAllowed::dispatch($result->user);

        return redirect()->route('home');
    }

    private function fail(string $reason, string $message): RedirectResponse
    {
        OidcSignInDenied::dispatch($reason);

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
