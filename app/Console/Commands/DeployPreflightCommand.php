<?php

namespace App\Console\Commands;

use App\Support\DemoAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Guarded-deploy preflight checks (issue #3).
 *
 * Read-only: this command never migrates, activates a release, or
 * restarts anything. It only reports whether it would be safe to
 * proceed, so the deploy workflow (and a human) can decide.
 *
 * Deliberately a single `php artisan ...` invocation with no shell-specific
 * syntax, so it runs identically from PowerShell, cmd, or bash - satisfies
 * the "PowerShell-friendly dry-run/preflight command for Windows operators"
 * requirement without a separate .ps1/.sh script to keep in sync.
 *
 *   php artisan deploy:preflight
 *   php artisan deploy:preflight --target=production
 */
class DeployPreflightCommand extends Command
{
    protected $signature = 'deploy:preflight
        {--target=beta : Which environment this preflight is being run against (beta|production)}
        {--dry-run : Present for forward-compatibility; this command never mutates state regardless}';

    protected $description = 'Report whether the current build/environment is safe to deploy, without changing anything.';

    /** @var array<int, array{name: string, ok: bool, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $target = $this->option('target');

        $this->info("Deploy preflight for target: {$target}");
        $this->newLine();

        $this->checkGitState();
        $this->checkRequiredEnv($target);
        $this->checkDebugDisabledForGuardedTargets($target);
        $this->checkDatabase();
        $this->checkRedis();
        $this->checkPendingMigrations();
        $this->checkDemoAccounts();

        $this->newLine();
        $this->renderResults();

        $failed = collect($this->results)->contains(fn (array $r) => ! $r['ok']);

        if ($failed) {
            $this->newLine();
            $this->error('Preflight failed. Do not proceed with deployment.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Preflight passed.');

        return self::SUCCESS;
    }

    private function record(string $name, bool $ok, string $detail): void
    {
        $this->results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    private function checkGitState(): void
    {
        if (app()->environment('testing')) {
            $this->record('git.clean_tree', true, 'skipped in testing environment');

            return;
        }

        if (! is_dir(base_path('.git'))) {
            $this->record('git.clean_tree', true, 'skipped: not a git checkout (deployed artifact)');

            return;
        }

        try {
            $status = new Process(['git', 'status', '--porcelain'], base_path());
            $status->run();
            $clean = trim($status->getOutput()) === '';

            $sha = new Process(['git', 'rev-parse', '--short', 'HEAD'], base_path());
            $sha->run();
            $commit = trim($sha->getOutput()) ?: 'unknown';

            $this->record(
                'git.clean_tree',
                $clean,
                $clean ? "clean at {$commit}" : "uncommitted changes present at {$commit}"
            );
        } catch (Throwable $e) {
            $this->record('git.clean_tree', false, 'could not determine git state: '.$e->getMessage());
        }
    }

    private function checkRequiredEnv(string $target): void
    {
        $required = ['APP_KEY', 'APP_URL'];

        $missing = collect($required)->filter(fn (string $key) => blank(env($key)))->values();

        $this->record(
            'env.required_vars_present',
            $missing->isEmpty(),
            $missing->isEmpty() ? 'present' : 'missing: '.$missing->join(', ')
        );

        $hasDb = filled(env('CLOUDRON_MYSQL_HOST')) || filled(env('DB_HOST')) || config('database.default') === 'sqlite';
        $this->record('env.database_configured', $hasDb, $hasDb ? 'ok' : 'no CLOUDRON_MYSQL_* or DB_* configuration found');
    }

    private function checkDebugDisabledForGuardedTargets(string $target): void
    {
        if ($target === 'beta' || $target === 'production') {
            $debugOff = config('app.debug') === false;
            $this->record(
                'env.debug_disabled',
                $debugOff,
                $debugOff ? 'APP_DEBUG=false' : 'APP_DEBUG must be false for beta/production'
            );

            return;
        }

        $this->record('env.debug_disabled', true, 'skipped for target '.$target);
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->record('database.reachable', true, 'connected');
        } catch (Throwable $e) {
            $this->record('database.reachable', false, 'could not connect');
        }
    }

    private function checkRedis(): void
    {
        $usesRedis = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);

        if (! $usesRedis) {
            $this->record('redis.reachable', true, 'skipped: not configured as cache/queue/session driver');

            return;
        }

        try {
            Redis::connection()->ping();
            $this->record('redis.reachable', true, 'connected');
        } catch (Throwable $e) {
            $this->record('redis.reachable', false, 'could not connect');
        }
    }

    private function checkPendingMigrations(): void
    {
        try {
            Artisan::call('migrate:status', ['--pending' => true]);
            $output = Artisan::output();
            $hasPending = trim($output) !== ''
                && ! str_contains($output, 'No pending migrations')
                && ! str_contains($output, 'No migrations found');

            $this->record(
                'database.migrations_pending',
                true,
                $hasPending ? 'pending migrations exist - deploy will run them' : 'up to date'
            );
        } catch (Throwable $e) {
            $this->record('database.migrations_pending', false, 'could not determine migration status');
        }
    }

    /**
     * Warning only: demo accounts are reported but never fail preflight.
     */
    private function checkDemoAccounts(): void
    {
        try {
            $emails = DemoAccounts::query()->pluck('email');
        } catch (Throwable $e) {
            $this->record('security.demo_accounts', true, 'skipped: could not query users');

            return;
        }

        $this->record(
            'security.demo_accounts',
            true,
            $emails->isEmpty()
                ? 'none'
                : 'WARNING: demo accounts with a known password exist ('.$emails->join(', ').') - delete them'
        );
    }

    private function renderResults(): void
    {
        $this->table(
            ['Check', 'Result', 'Detail'],
            collect($this->results)->map(fn (array $r) => [
                $r['name'],
                $r['ok'] ? '<info>PASS</info>' : '<error>FAIL</error>',
                $r['detail'],
            ])->all()
        );
    }
}
