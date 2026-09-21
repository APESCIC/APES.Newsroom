<?php

namespace App\Console\Commands;

use App\Services\Releases\ChangelogEntryValidator;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * CI gate: require a changelog/releases entry on the PR unless skip-changelog.
 */
class CheckPrChangelogCommand extends Command
{
    protected $signature = 'newsroom:check-pr-changelog
        {--base=origin/main : Git ref to diff against}
        {--skip-label=skip-changelog : Label name that skips the gate}
        {--labels= : Comma-separated PR labels (CI passes this from the event)}';

    protected $description = 'Fail if the PR does not add/update a valid changelog/releases entry (unless skip label).';

    public function handle(ChangelogEntryValidator $validator): int
    {
        $labels = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('labels')),
        )));
        $skipLabel = (string) $this->option('skip-label');

        if (in_array($skipLabel, $labels, true)) {
            $this->info("PR has label {$skipLabel}; changelog gate skipped.");

            return self::SUCCESS;
        }

        $base = (string) $this->option('base');
        $changed = $this->changedChangelogFiles($base);

        if ($changed === []) {
            $this->error('No changelog/releases/*.json entry added or updated.');
            $this->line('Add a file like changelog/releases/v1.2.3.json (see changelog/releases/_template.json)');
            $this->line('or label the PR with '.$skipLabel.' for non-user-facing work.');
            $this->line('Docs: docs/change-log-hub.md');

            return self::FAILURE;
        }

        if (count($changed) > 1) {
            $this->warn('Multiple changelog entries changed ('.count($changed).'). Routine PRs should add exactly one version file.');
        }

        $failed = false;
        foreach ($changed as $relative) {
            $absolute = base_path($relative);
            $errors = $validator->validateFile($absolute);
            if ($errors !== []) {
                $failed = true;
                $this->error("Invalid changelog entry: {$relative}");
                foreach ($errors as $error) {
                    $this->line(' - '.$error);
                }

                continue;
            }

            $this->info("Changelog entry OK: {$relative}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function changedChangelogFiles(string $base): array
    {
        $process = new Process([
            'git', 'diff', '--name-only', '--diff-filter=AM', "{$base}...HEAD", '--', 'changelog/releases/',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            // Fallback for shallow clones / missing base: compare against merge-base if possible.
            $process = new Process([
                'git', 'diff', '--name-only', '--diff-filter=AM', $base, 'HEAD', '--', 'changelog/releases/',
            ]);
            $process->run();
        }

        if (! $process->isSuccessful()) {
            $this->error('git diff failed: '.$process->getErrorOutput());

            return [];
        }

        $files = preg_split('/\R/', trim($process->getOutput())) ?: [];
        $files = array_values(array_filter(
            $files,
            function (string $file): bool {
                if ($file === '' || ! str_ends_with($file, '.json')) {
                    return false;
                }

                return ! str_starts_with(basename($file), '_');
            },
        ));

        return $files;
    }
}
