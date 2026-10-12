<?php

namespace Tests;

use App\Services\Auth\RecentAuthentication;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Mark the session as having just confirmed the user's identity, as an
     * interactive sign-in would; actingAs() alone does not.
     */
    protected function withRecentAuthentication(): static
    {
        return $this->withSession([RecentAuthentication::SESSION_KEY => now()->getTimestamp()]);
    }
}
