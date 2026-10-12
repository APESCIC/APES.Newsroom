<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentAuthentication;
use App\Services\Auth\SsoOnlyPolicy;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function __construct(
        private readonly RecentAuthentication $recentAuth,
        private readonly UserSessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function update(Request $request, SsoOnlyPolicy $ssoOnly): RedirectResponse
    {
        $user = $request->user();

        abort_if($ssoOnly->refuses($user, 'password_change', $request), 403, 'Your password is managed by APES staff sign-in.');

        $settingFirstPassword = blank($user->password);

        // Without a current password to check, a recent identity confirmation stands in for it.
        if ($settingFirstPassword && ! $this->recentAuth->isRecent($request)) {
            return $this->recentAuth->challenge($request);
        }

        $request->validate([
            'current_password' => $settingFirstPassword ? ['prohibited'] : ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill(['password' => $request->input('password')])->save();

        $this->sessions->revokeOthers($user, $request);
        $this->recentAuth->stamp($request);
        $this->audit->record($user, 'account.password_changed', $user, [
            'first_password' => $settingFirstPassword,
        ], $request);

        return back()->with('status', 'password-updated');
    }
}
