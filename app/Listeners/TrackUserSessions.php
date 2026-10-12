<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\Security\NewDeviceSignInNotification;
use App\Services\Auth\SessionTracker;
use App\Support\IpAddress;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Records sign-ins and sign-outs in user_sessions and warns the account
 * holder about sign-ins from unfamiliar devices (registered by Laravel
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

        $session = $this->tracker->start($event->user, $this->request, $event->remember);

        if ($session->wasRecentlyCreated && $this->tracker->isUnfamiliarDevice($session)) {
            $device = $session->browser ?? 'Unknown browser';

            $event->user->notify(new NewDeviceSignInNotification(
                $session->platform ? "{$device} on {$session->platform}" : $device,
                IpAddress::mask($session->ip_address),
                now()->timezone((string) config('app.timezone'))->format('j M Y, H:i T'),
            ));
        }
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
