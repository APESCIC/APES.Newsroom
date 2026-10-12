<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Passkeys\PasskeyCeremony;
use App\Services\Auth\PendingTwoFactorLogin;
use App\Services\Auth\SsoOnlyPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless sign-in with a passkey. A passkey that verified the user
 * (PIN or biometric) counts as two factors and skips the authenticator-app
 * challenge; a presence-only passkey is treated like a password.
 */
class PasskeyLoginController extends Controller
{
    public function __construct(
        private readonly PasskeyCeremony $ceremony,
        private readonly PendingTwoFactorLogin $login,
        private readonly SsoOnlyPolicy $ssoOnly,
        private readonly AuditLogger $audit,
    ) {}

    public function options(Request $request): Response
    {
        return response($this->ceremony->authenticationOptions($request), 200, ['Content-Type' => 'application/json']);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'credential' => ['required', 'string', 'max:16000'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $assertion = $this->ceremony->authenticate($request, $validated['credential']);
        $user = $assertion?->passkey->authenticatable;

        if (! $user instanceof User || $this->ssoOnly->refuses($user, 'passkey_login', $request)) {
            throw ValidationException::withMessages(['passkey' => 'That passkey could not be used to sign in.']);
        }

        $this->audit->record($user, 'auth.passkey_login', $user, [
            'passkey_id' => $assertion->passkey->getKey(),
            'user_verified' => $assertion->userVerified,
        ], $request);

        return $this->login->signIn(
            $request,
            $user,
            $request->boolean('remember'),
            'passkey',
            route('home'),
            multiFactor: $assertion->userVerified,
        );
    }
}
