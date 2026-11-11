<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Throwable;

/**
 * DB-IP publishes the free Lite City database monthly under CC BY 4.0.
 * The file is replaced only after the new download opens as a City
 * database, so a failed or partial download never removes the old one.
 */
class UpdateGeoIpDatabaseCommand extends Command
{
    protected $signature = 'newsroom:geoip-update {--if-missing : Only download when no database file exists yet}';

    protected $description = 'Download the monthly DB-IP Lite City database used for approximate session locations';

    private const CHUNK_BYTES = 1048576;

    public function handle(): int
    {
        $target = (string) config('security.geoip.database');

        if ($this->option('if-missing') && is_file($target)) {
            $this->info('GeoIP database already present; nothing to do.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($target));
        $download = $target.'.download.gz';
        $unpacked = $target.'.new';

        $now = CarbonImmutable::now();

        try {
            foreach ([$now, $now->subMonthNoOverflow()] as $month) {
                $url = str_replace('{month}', $month->format('Y-m'), (string) config('security.geoip.download_url'));

                if (! $this->download($url, $download)) {
                    continue;
                }

                if (! $this->gunzip($download, $unpacked) || ! $this->isCityDatabase($unpacked)) {
                    $this->error("The file from {$url} is not a readable DB-IP City database; the current database was kept.");

                    return self::FAILURE;
                }

                rename($unpacked, $target);
                $this->info("GeoIP database updated from {$url}.");

                return self::SUCCESS;
            }
        } finally {
            File::delete([$download, $unpacked]);
        }

        $this->error('No DB-IP Lite City database could be downloaded for this month or last month.');

        return self::FAILURE;
    }

    private function download(string $url, string $path): bool
    {
        try {
            $response = Http::timeout(600)->withOptions(['stream' => true])->get($url);
        } catch (Throwable $e) {
            $this->warn("Download failed: {$e->getMessage()}");

            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $body = $response->toPsrResponse()->getBody();
        $out = fopen($path, 'wb');
        if ($out === false) {
            return false;
        }

        while (! $body->eof()) {
            fwrite($out, $body->read(self::CHUNK_BYTES));
        }

        fclose($out);

        return filesize($path) > 0;
    }

    private function gunzip(string $from, string $to): bool
    {
        $in = @gzopen($from, 'rb');
        $out = fopen($to, 'wb');

        if ($in === false || $out === false) {
            return false;
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, self::CHUNK_BYTES);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);

        return filesize($to) > 0;
    }

    private function isCityDatabase(string $path): bool
    {
        try {
            $reader = new Reader($path);
            $type = $reader->metadata()->databaseType;
            $reader->close();

            return str_contains($type, 'City');
        } catch (Throwable) {
            return false;
        }
    }
}
