<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\UserSession;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionTracker;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionTracker $tracker,
        private readonly UserSessionRevoker $revoker,
        private readonly AuditLogger $audit,
    ) {}

    public function destroy(Request $request, UserSession $session): RedirectResponse
    {
        $user = $request->user();

        abort_unless((int) $session->user_id === (int) $user->getKey(), 404);

        if ($session->is($this->tracker->current($request))) {
            return back()->withErrors(['session' => 'Use "Log out" to end the session on this device.']);
        }

        $this->tracker->end($session);

        // "Remember me" uses one token per account, so ending a remembered
        // session invalidates every remember-me cookie for the user.
        if ($session->remembered) {
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }

        $this->audit->record($user, 'account.session_ended', $user, [
            'session_id' => $session->getKey(),
            'remembered' => $session->remembered,
        ], $request);

        return back()->with('status', 'session-ended');
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->revoker->revokeOthers($user, $request);

        $this->audit->record($user, 'account.other_sessions_ended', $user, [], $request);

        return back()->with('status', 'other-sessions-ended');
    }
}
