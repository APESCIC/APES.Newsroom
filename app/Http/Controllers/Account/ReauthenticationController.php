<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ReauthenticationCodeNotification;
use App\Services\Auth\RecentAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReauthenticationController extends Controller
{
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(private readonly RecentAuthentication $recentAuth) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Account/ConfirmIdentity', [
            'method' => $this->recentAuth->methodFor($request->user()),
            'status' => session('status'),
        ]);
    }

    public function confirmPassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->ensureMethod($user, RecentAuthentication::METHOD_PASSWORD);

        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            $this->recentAuth->fail($request, $user, RecentAuthentication::METHOD_PASSWORD);

            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $this->recentAuth->confirm($request, $user, RecentAuthentication::METHOD_PASSWORD);

        return redirect()->intended(route('account.show'));
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->ensureMethod($user, RecentAuthentication::METHOD_EMAIL_CODE);

        $minutes = max(1, (int) config('security.reauth_code_ttl_minutes', 10));
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes($minutes);

        Cache::put($this->cacheKey($user), [
            'hash' => $this->hashCode($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        $user->notify(new ReauthenticationCodeNotification($code, $minutes));

        return back()->with('status', 'code-sent');
    }

    public function confirmCode(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->ensureMethod($user, RecentAuthentication::METHOD_EMAIL_CODE);

        $request->validate(['code' => ['required', 'string', 'size:6']]);

        $key = $this->cacheKey($user);
        $pending = Cache::get($key);

        if (! is_array($pending) || $pending['expires_at'] < now()->getTimestamp()) {
            Cache::forget($key);

            throw ValidationException::withMessages(['code' => 'That code has expired. Send a new one.']);
        }

        if (! hash_equals($pending['hash'], $this->hashCode((string) $request->input('code')))) {
            $this->recentAuth->fail($request, $user, RecentAuthentication::METHOD_EMAIL_CODE);
            $pending['attempts']++;

            if ($pending['attempts'] >= self::MAX_CODE_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => 'Too many incorrect codes. Send a new one.']);
            }

            Cache::put($key, $pending, now()->setTimestamp($pending['expires_at']));

            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        Cache::forget($key);
        $this->recentAuth->confirm($request, $user, RecentAuthentication::METHOD_EMAIL_CODE);

        return redirect()->intended(route('account.show'));
    }

    private function ensureMethod(User $user, string $method): void
    {
        abort_unless($this->recentAuth->methodFor($user) === $method, 403);
    }

    private function cacheKey(User $user): string
    {
        return 'reauth-code:'.$user->id;
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
