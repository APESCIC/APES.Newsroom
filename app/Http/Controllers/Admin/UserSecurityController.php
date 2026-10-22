<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AccountLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin actions on another account's security. Every action needs recent
 * re-authentication (route middleware) and a reason for the audit log.
 */
class UserSecurityController extends Controller
{
    public function __construct(private readonly AccountLockService $locks) {}

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

    private function reason(Request $request): string
    {
        return trim((string) $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ])['reason']);
    }
}
