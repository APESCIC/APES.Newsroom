<?php

namespace App\Console\Commands;

use App\Services\Import\GhostContentImporter;
use Illuminate\Console\Command;

class BackfillGhostPostChannelsCommand extends Command
{
    protected $signature = 'ghost:backfill-post-channels
        {json : Path to Ghost content JSON export}
        {--dry-run : List changes without writing}
        {--force : Persist changes (required when not using --dry-run)}';

    protected $description = 'Refile imported Ghost posts under APES Times, MyAPES Portal and Stream 4 APES (#527)';

    public function handle(GhostContentImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('force');

        if (! $this->option('dry-run') && ! $this->option('force')) {
            $this->warn('Refusing to write without --force. Running as dry-run. Pass --force to persist.');
        }

        $report = $importer->backfillPostChannelsFromExport((string) $this->argument('json'), $dryRun);

        if ($report['changes'] !== []) {
            $this->table(
                ['Ghost id', 'Slug', 'Title', 'From', 'To'],
                array_map(fn (array $change) => [
                    $change['ghost_id'],
                    $change['slug'],
                    $change['title'],
                    $change['from'],
                    $change['to'],
                ], $report['changes']),
            );
        }

        $this->info(sprintf(
            '%s %d post(s) %s; %d already filed correctly; %d not found locally.',
            $dryRun ? 'Dry-run:' : 'Done:',
            $report['reassigned'],
            $dryRun ? 'would be reassigned' : 'reassigned',
            $report['unchanged'],
            $report['missing'],
        ));

        return self::SUCCESS;
    }
}
