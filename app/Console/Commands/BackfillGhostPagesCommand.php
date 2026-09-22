<?php

namespace App\Console\Commands;

use App\Services\Import\GhostContentImporter;
use Illuminate\Console\Command;

class BackfillGhostPagesCommand extends Command
{
    protected $signature = 'ghost:backfill-pages
        {json : Path to Ghost content JSON export}
        {--dry-run : Count and report without writing}
        {--force : Persist changes (required when not using --dry-run)}';

    protected $description = 'Backfill Ghost type:page rows into Page models with article→page redirects (#78)';

    public function handle(GhostContentImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('force');

        if (! $this->option('dry-run') && ! $this->option('force')) {
            $this->warn('Refusing to write without --force. Running as dry-run. Pass --force to persist.');
            $dryRun = true;
        }

        $report = $importer->backfillPagesFromExport(
            (string) $this->argument('json'),
            $dryRun,
        );

        $this->info($dryRun ? 'Dry-run complete.' : 'Backfill complete.');
        $this->line(json_encode($report, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
