<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AccountLockService;
use App\Services\Admin\AdminSecurityActions;
use App\Services\Admin\UserRoleChanger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin actions on another account's security. Every action needs recent
 * re-authentication (route middleware) and a reason for the audit log.
 */
class UserSecurityController extends Controller
{
    public function __construct(
        private readonly AccountLockService $locks,
        private readonly AdminSecurityActions $actions,
    ) {}

    public function lock(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageSecurity', $user);
        $reason = $this->reason($request);

        if ($user->isLocked()) {
            throw ValidationException::withMessages(['reason' => 'This account is already locked.']);
        }

        $this->locks->lock($user, $request->user(), $reason, $request);

        return back()->with('status', 'Account locked and signed out everywhere.');
    }

    public function unlock(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageSecurity', $user);
        $reason = $this->reason($request);

        if (! $user->isLocked()) {
            throw ValidationException::withMessages(['reason' => 'This account is not locked.']);
        }

        $this->locks->unlock($user, $request->user(), $reason, $request);

        return back()->with('status', 'Account unlocked.');
    }

    public function signOut(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageSecurity', $user);

        $this->actions->signOutEverywhere($user, $request->user(), $this->reason($request), $request);

        return back()->with('status', 'Signed out of every session.');
    }

    public function forcePasswordReset(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageSecurity', $user);
        $reason = $this->reason($request);
        $this->refuseForSso($user);

        if (blank($user->password)) {
            throw ValidationException::withMessages(['reason' => 'This account signs in with magic links and has no password to reset.']);
        }

        $this->actions->forcePasswordReset($user, $request->user(), $reason, $request);

        return back()->with('status', 'Password cleared and a reset link sent.');
    }

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageSecurity', $user);
        $reason = $this->reason($request);
        $this->refuseForSso($user);

        if ($user->two_factor_secret === null) {
            throw ValidationException::withMessages(['reason' => 'Two-factor sign-in is not set up on this account.']);
        }

        $this->actions->resetTwoFactor($user, $request->user(), $reason, $request);

        return back()->with('status', 'Two-factor sign-in turned off.');
    }

    public function removePasskeys(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageSecurity', $user);
        $reason = $this->reason($request);
        $this->refuseForSso($user);

        if (! $user->passkeys()->exists()) {
            throw ValidationException::withMessages(['reason' => 'This account has no passkeys.']);
        }

        $removed = $this->actions->removePasskeys($user, $request->user(), $reason, $request);

        return back()->with('status', "Removed {$removed} passkey".($removed === 1 ? '' : 's').'.');
    }

    public function changeRole(Request $request, User $user, UserRoleChanger $roles): RedirectResponse
    {
        Gate::authorize('changeRole', $user);
        $reason = $this->reason($request);
        $validated = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);

        $roles->change($user, Role::from($validated['role']), $request->user(), $reason, $request);

        return back()->with('status', 'Role changed and sessions signed out.');
    }

    /**
     * Cloudron SSO owns the credentials of SSO-managed accounts; only
     * signing out is possible from here.
     */
    private function refuseForSso(User $user): void
    {
        if ($user->requiresSso()) {
            throw ValidationException::withMessages(['reason' => 'Credentials for this account are managed by Cloudron SSO.']);
        }
    }

    private function reason(Request $request): string
    {
        return trim((string) $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ])['reason']);
    }
}
