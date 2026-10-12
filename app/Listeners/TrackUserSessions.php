<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\SessionTracker;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Records sign-ins and sign-outs in user_sessions (registered by Laravel
 * event discovery).
 */
class TrackUserSessions
{
    public function __construct(
        private readonly SessionTracker $tracker,
        private readonly Request $request,
    ) {}

    public function handleLogin(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        $this->tracker->start($event->user, $this->request, $event->remember);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard !== 'web' || ! $this->request->hasSession()) {
            return;
        }

        $session = $this->tracker->current($this->request);

        if ($session !== null) {
            $this->tracker->end($session);
        }

        $this->request->session()->forget(SessionTracker::SESSION_KEY);
        $this->tracker->forgetDevice();
    }
}
