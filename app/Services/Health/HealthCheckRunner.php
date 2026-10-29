<?php

namespace App\Services\Health;

use App\Models\IntegrationCheckResult;
use App\Models\User;
use App\Services\Health\Checks\CloudronOidcCheck;
use App\Services\Health\Checks\CustomSmtpCheck;
use App\Services\Health\Checks\MailgunCheck;
use App\Services\Health\Checks\OpenAiCheck;
use App\Services\Health\Checks\SlackCheck;
use App\Services\Health\Checks\StripeCheck;
use App\Services\Health\Checks\UnsplashCheck;
use App\Services\Health\Contracts\IntegrationCheck;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class HealthCheckRunner
{
    /** @var list<class-string<IntegrationCheck>> */
    private const CHECKS = [
        MailgunCheck::class,
        StripeCheck::class,
        UnsplashCheck::class,
        OpenAiCheck::class,
        SlackCheck::class,
        CloudronOidcCheck::class,
        CustomSmtpCheck::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, IntegrationCheck>
     */
    public function checks(): array
    {
        $checks = [];

        foreach (self::CHECKS as $class) {
            $check = $this->container->make($class);
            $checks[$check->provider()] = $check;
        }

        return $checks;
    }

    public function check(string $provider): IntegrationCheck
    {
        return $this->checks()[$provider]
            ?? throw new InvalidArgumentException("No health check for provider [{$provider}].");
    }

    /**
     * Run one provider's check and store the result.
     *
     * @param  array<string, string>  $overrides
     */
    public function run(string $provider, string $trigger, ?User $user = null, array $overrides = []): IntegrationCheckResult
    {
        $check = $this->check($provider);
        $started = hrtime(true);

        $result = $check->configured($overrides)
            ? $this->safely($check, $overrides)
            : CheckResult::fail('Not configured.');

        return IntegrationCheckResult::query()->create([
            'provider' => $provider,
            'status' => $result->status,
            'message' => mb_substr($result->message, 0, 255),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'trigger' => $trigger,
            'user_id' => $user?->id,
            'checked_at' => now(),
        ]);
    }

    /**
     * Run every configured provider's check.
     *
     * @return array<string, IntegrationCheckResult>
     */
    public function runConfigured(string $trigger, ?User $user = null): array
    {
        $results = [];

        foreach ($this->checks() as $provider => $check) {
            if ($check->configured()) {
                $results[$provider] = $this->run($provider, $trigger, $user);
            }
        }

        return $results;
    }

    /**
     * Exception text is never stored or logged: HTTP client errors can
     * include request headers or URLs that carry keys.
     *
     * @param  array<string, string>  $overrides
     */
    private function safely(IntegrationCheck $check, array $overrides): CheckResult
    {
        $label = IntegrationDefinitions::provider($check->provider())['label'];

        try {
            return $check->run($overrides);
        } catch (ConnectionException) {
            return CheckResult::fail("Could not reach {$label} (timed out or connection refused).");
        } catch (Throwable $e) {
            Log::warning('Integration check failed unexpectedly.', [
                'provider' => $check->provider(),
                'exception' => $e::class,
            ]);

            return CheckResult::fail("The {$label} check failed unexpectedly.");
        }
    }
}
