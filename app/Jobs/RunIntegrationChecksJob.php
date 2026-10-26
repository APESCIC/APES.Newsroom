<?php

namespace App\Jobs;

use App\Models\IntegrationCheckResult;
use App\Services\Health\HealthCheckRunner;
use App\Services\Health\IntegrationAlerts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunIntegrationChecksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3000;

    public function __construct(public readonly ?string $provider = null) {}

    public function handle(HealthCheckRunner $runner, IntegrationAlerts $alerts): void
    {
        foreach ($runner->checks() as $provider => $check) {
            if (($this->provider !== null && $provider !== $this->provider) || ! $check->configured()) {
                continue;
            }

            $previous = IntegrationCheckResult::latestFor($provider);
            $current = $runner->run($provider, IntegrationCheckResult::TRIGGER_SCHEDULE);

            $alerts->afterRun($previous, $current);
        }
    }

    public function uniqueId(): string
    {
        return $this->provider ?? 'all';
    }
}
