<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\PlaceholderImages;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * Loads (or resets and reloads) the local demo data described in
 * docs/herd/demo-data.md. Local only: never call it from deploys,
 * Cloudron start scripts or production.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'newsroom:demo-data
        {--reset : Drop every table in this local database, migrate and reload the demo data}';

    protected $description = 'Load fake demo data into the local Herd database (APP_ENV=local only)';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Refused: demo data only loads when APP_ENV=local (this app is "'.$this->laravel->environment().'").');

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $problem = $this->resetBlocker();

            if ($problem !== null) {
                $this->error('Refused to reset: '.$problem);

                return self::FAILURE;
            }

            PlaceholderImages::clear();

            if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if ($this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if (! is_dir(public_path('storage'))) {
            $this->warn('public/storage is missing, so demo images will not show. Run: php artisan storage:link');
        }

        $this->info('Demo data loaded. Logins are listed in docs/herd/demo-data.md.');

        return self::SUCCESS;
    }

    /**
     * A reset wipes the database, so it only runs against this project's
     * own Herd MySQL database.
     */
    private function resetBlocker(): ?string
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");
        $database = (string) ($config['database'] ?? '');
        $host = (string) ($config['host'] ?? '');

        if (($config['driver'] ?? null) !== 'mysql') {
            return "the database connection is \"{$connection}\", not Herd's MySQL.";
        }

        if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return "the database host is \"{$host}\", not 127.0.0.1 or localhost.";
        }

        if (! str_ends_with($database, '_local')) {
            return "the database \"{$database}\" does not end in _local.";
        }

        return null;
    }
}
