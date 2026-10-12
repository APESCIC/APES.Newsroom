<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Passkeys\PasskeyCeremony;
use App\Services\Auth\PendingTwoFactorLogin;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly PendingTwoFactorLogin $login,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
        private readonly PasskeyCeremony $passkeys,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $this->login->user($request);

        if (! $user) {
            return $this->restart();
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'has_passkeys' => $user->passkeys()->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->login->user($request);

        if (! $user) {
            return $this->restart();
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:20', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:40'],
        ]);

        if (filled($validated['recovery_code'] ?? null)) {
            if ($this->twoFactor->consumeRecoveryCode($user, $validated['recovery_code'])) {
                $this->audit->record($user, 'auth.recovery_code_used', $user, [
                    'remaining' => $this->twoFactor->remainingRecoveryCodes($user),
                ], $request);

                return $this->login->complete($request, $user, 'recovery_code', route('account.security'))
                    ->with('status', 'recovery-code-used');
            }

            return $this->failed($request, $user, 'recovery_code', 'That recovery code did not work. Each code can only be used once.');
        }

        if ($this->twoFactor->verify($user, (string) $validated['code'])) {
            return $this->login->complete($request, $user, 'totp');
        }

        return $this->failed($request, $user, 'code', 'That code did not work. Enter the latest code from your authenticator app.');
    }

    public function passkeyOptions(Request $request): HttpResponse
    {
        $user = $this->login->user($request);
        abort_unless($user && $user->passkeys()->exists(), 404);

        return response($this->passkeys->authenticationOptions($request, $user), 200, ['Content-Type' => 'application/json']);
    }

    public function passkey(Request $request): RedirectResponse
    {
        $user = $this->login->user($request);

        if (! $user) {
            return $this->restart();
        }

        $validated = $request->validate(['credential' => ['required', 'string', 'max:16000']]);
        $assertion = $this->passkeys->authenticate($request, $validated['credential']);

        if ($assertion && (int) $assertion->passkey->authenticatable_id === (int) $user->getKey()) {
            return $this->login->complete($request, $user, 'passkey');
        }

        return $this->failed($request, $user, 'passkey', 'That passkey could not be used. Try again or enter a code instead.');
    }

    private function failed(Request $request, User $user, string $field, string $message): RedirectResponse
    {
        if ($this->login->fail($request, $user, $field === 'code' ? 'totp' : $field)) {
            return $this->restart('Too many incorrect codes. Please sign in again.');
        }

        throw ValidationException::withMessages([$field => $message]);
    }

    private function restart(string $message = 'Your sign-in has expired. Please sign in again.'): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
