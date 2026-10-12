<?php

namespace Tests;

use App\Models\UserSession;
use App\Services\Auth\RecentAuthentication;
use App\Services\Auth\SessionTracker;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Switching user with actingAs() keeps the test session, unlike a real
     * sign-in; drop the previous user's tracked-session key so it is not
     * treated as a session belonging to someone else.
     */
    public function be(UserContract $user, $guard = null)
    {
        $this->startSession();
        $key = $this->app['session']->get(SessionTracker::SESSION_KEY);

        if (is_string($key) && ! UserSession::query()
            ->where('session_key_hash', hash('sha256', $key))
            ->where('user_id', $user->getAuthIdentifier())
            ->exists()) {
            $this->app['session']->forget(SessionTracker::SESSION_KEY);
        }

        return parent::be($user, $guard);
    }

    /**
     * Mark the session as having just confirmed the user's identity, as an
     * interactive sign-in would; actingAs() alone does not.
     */
    protected function withRecentAuthentication(): static
    {
        return $this->withSession([RecentAuthentication::SESSION_KEY => now()->getTimestamp()]);
    }
}
