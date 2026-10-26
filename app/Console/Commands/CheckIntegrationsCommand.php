<?php

namespace App\Console\Commands;

use App\Jobs\RunIntegrationChecksJob;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Console\Command;

class CheckIntegrationsCommand extends Command
{
    protected $signature = 'integrations:check
        {--provider= : Only check this provider}
        {--now : Run in this process instead of queueing}';

    protected $description = 'Run read-only health checks for configured integrations and alert admins when one starts failing';

    public function handle(): int
    {
        $provider = $this->option('provider');

        if ($provider !== null && ! in_array($provider, IntegrationDefinitions::providerNames(), true)) {
            $this->error('Unknown provider. Expected one of: '.implode(', ', IntegrationDefinitions::providerNames()).'.');

            return self::FAILURE;
        }

        if ($this->option('now')) {
            RunIntegrationChecksJob::dispatchSync($provider);
            $this->info('Integration checks finished.');

            return self::SUCCESS;
        }

        RunIntegrationChecksJob::dispatch($provider);
        $this->info('Integration checks queued.');

        return self::SUCCESS;
    }
}
