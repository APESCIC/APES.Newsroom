<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Security\PasswordChangedNotification;
use App\Services\Auth\AccountLockPolicy;
use App\Services\Auth\SsoOnlyPolicy;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email'),
            'token' => $request->route('token'),
        ]);
    }

    public function store(Request $request, SsoOnlyPolicy $ssoOnly, AccountLockPolicy $locks, UserSessionRevoker $sessions): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if ($ssoOnly->refuses($user, 'password_reset_complete', $request) || $locks->refuses($user, 'password_reset_complete', $request)) {
            throw ValidationException::withMessages([
                'email' => [__(Password::INVALID_TOKEN)],
            ]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($sessions) {
                $user->forceFill(['password' => Hash::make($password)])->save();

                $sessions->revoke($user);

                event(new PasswordReset($user));
                $user->notify(new PasswordChangedNotification(viaReset: true));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return redirect()->route('login')->with('status', __($status));
    }
}
