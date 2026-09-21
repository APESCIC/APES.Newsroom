<?php

namespace App\Console\Commands;

use App\Services\Releases\ReleaseChangelogSync;
use Illuminate\Console\Command;
use Throwable;

class SyncReleasesCommand extends Command
{
    protected $signature = 'newsroom:sync-releases';

    protected $description = 'Upsert Change Log Hub releases from changelog/releases/*.json';

    public function handle(ReleaseChangelogSync $sync): int
    {
        try {
            $count = $sync->sync();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Synced {$count} release(s) from changelog/releases.");

        return self::SUCCESS;
    }
}
