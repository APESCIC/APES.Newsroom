<?php

namespace App\Services\Mail;

use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limits admin test emails per admin per minute and per target (a sending
 * domain or a transport) per 24 hours. Called from controllers after
 * authorization and validation, so refused requests never use up a limit.
 */
class TestSendThrottle
{
    private const DAY = 86400;

    /**
     * Count one test, or return why it is refused.
     */
    public function attempt(User $user, string $target, string $subject = 'This domain'): ?string
    {
        $userKey = 'test-send:user:'.$user->getKey();
        $targetKey = 'test-send:target:'.$target;
        $perMinute = max(1, (int) config('mailing.test_send.per_user_per_minute', 5));
        $perDay = max(1, (int) config('mailing.test_send.per_domain_per_day', 20));

        if (RateLimiter::tooManyAttempts($userKey, $perMinute)) {
            return 'You have sent several test emails in the last minute. Please wait a minute and try again.';
        }

        if (RateLimiter::tooManyAttempts($targetKey, $perDay)) {
            $wait = CarbonInterval::seconds(max(60, RateLimiter::availableIn($targetKey)))->cascade()->forHumans(['parts' => 2, 'short' => false]);

            return "{$subject} has reached its limit of {$perDay} test emails in 24 hours. You can send another in {$wait}.";
        }

        RateLimiter::hit($userKey, 60);
        RateLimiter::hit($targetKey, self::DAY);

        return null;
    }
}
