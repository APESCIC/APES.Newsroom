<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Import\GhostNewsletterReconciler;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileGhostNewslettersCommand extends Command
{
    protected $signature = 'ghost:reconcile-newsletters
        {dir : Directory holding members.csv and one <key>.csv export per Ghost newsletter}
        {--apply : Write changes (default is a dry run)}
        {--from-run= : Id of the signed-off dry run to apply}
        {--accept-mismatch : Apply even though counts differ from the expected Ghost counts}
        {--actor= : Email of the admin recorded on the import run}';

    protected $description = 'Reconcile Newsroom newsletter subscriptions with Ghost per-newsletter exports (no email is sent)';

    public function handle(GhostNewsletterReconciler $reconciler): int
    {
        $apply = (bool) $this->option('apply');
        $fromRun = null;

        if ($apply) {
            $fromRun = ImportRun::query()->find((int) $this->option('from-run'));
            if (! $fromRun) {
                $this->error('--apply needs --from-run=<id> of a completed dry run.');

                return self::FAILURE;
            }
        }

        $actor = null;
        if ($this->option('actor')) {
            $actor = User::query()->where('email', $this->option('actor'))->first();
            if (! $actor) {
                $this->error('No user with that --actor email.');

                return self::FAILURE;
            }
        }

        try {
            $report = $reconciler->run(
                (string) $this->argument('dir'),
                apply: $apply,
                actor: $actor,
                fromRun: $fromRun,
                acceptMismatch: (bool) $this->option('accept-mismatch'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($apply ? 'Applied' : 'Dry run').' (import run #'.$report['import_run_id'].')');
        $this->line('Members in export: '.$report['members']['seen'].', opted out: '.$report['members']['opted_out']);

        $this->table(
            ['Ghost newsletter', 'Expected', 'In export', 'Match', 'Confirm', 'Already', 'Blocked', 'Remove', 'Kept', 'Confirmed after'],
            collect($report['newsletters'])->map(fn (array $row, string $key) => [
                $key,
                $row['expected'],
                $row['in_export'],
                $row['matches_expected'] ? 'yes' : 'NO',
                $row['to_confirm'],
                $row['already_confirmed'],
                array_sum($row['blocked']),
                $row['to_remove'],
                $row['newsroom_choices_kept'],
                $apply ? ($report['confirmed_after_actual'][$key] ?? '-') : ($row['confirmed_after'] ?? '-'),
            ])->values()->all(),
        );

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }
        foreach ($report['problems'] as $problem) {
            $this->error($problem);
        }

        if (! $apply && $report['reconciled']) {
            $this->info('Counts match Ghost. After sign-off, apply with --apply --from-run='.$report['import_run_id']);
        }

        return $apply || $report['reconciled'] ? self::SUCCESS : self::FAILURE;
    }
}
