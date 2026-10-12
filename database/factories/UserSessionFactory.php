<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserSession>
 */
class UserSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'session_key_hash' => hash('sha256', Str::random(64)),
            'ip_address' => '203.0.113.24',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            'browser' => 'Mobile Safari',
            'platform' => 'iOS',
            'device_type' => 'smartphone',
            'remembered' => false,
            'last_seen_at' => now(),
        ];
    }

    public function remembered(): static
    {
        return $this->state(['remembered' => true]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
