<?php

namespace App\Console\Commands;

use App\Support\DemoAccounts;
use Illuminate\Console\Command;

/**
 * Read-only warning used by deploy/cloudron-activate.sh (issue #281).
 * Always exits successfully so a warning never blocks a deploy.
 */
class CheckDemoAccountsCommand extends Command
{
    protected $signature = 'newsroom:check-demo-accounts';

    protected $description = 'Warn if seeded demo accounts (known password) exist in this database';

    public function handle(): int
    {
        $emails = DemoAccounts::query()->orderBy('email')->pluck('email');

        if ($emails->isEmpty()) {
            $this->info('No demo accounts found.');

            return self::SUCCESS;
        }

        $this->warn("WARNING: {$emails->count()} demo account(s) with a known password exist: {$emails->join(', ')}");
        $this->warn('Delete them outside local/testing environments.');

        return self::SUCCESS;
    }
}
