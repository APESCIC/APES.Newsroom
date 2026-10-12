<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PendingTwoFactorLogin;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly PendingTwoFactorLogin $login,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->login->user($request)) {
            return $this->restart();
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->login->user($request);

        if (! $user) {
            return $this->restart();
        }

        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);

        if ($this->twoFactor->verify($user, $validated['code'])) {
            return $this->login->complete($request, $user, 'totp');
        }

        if ($this->login->fail($request, $user, 'totp')) {
            return $this->restart('Too many incorrect codes. Please sign in again.');
        }

        throw ValidationException::withMessages([
            'code' => 'That code did not work. Enter the latest code from your authenticator app.',
        ]);
    }

    private function restart(string $message = 'Your sign-in has expired. Please sign in again.'): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
