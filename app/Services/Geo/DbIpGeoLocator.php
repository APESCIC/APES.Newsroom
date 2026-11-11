<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Looks addresses up in the local DB-IP Lite City database (MMDB format).
 * A missing or unreadable file means "no location", never an error.
 */
class DbIpGeoLocator implements GeoLocator
{
    private ?Reader $reader = null;

    private bool $opened = false;

    public function __construct(private readonly string $databasePath) {}

    public function locate(?string $ip): ?GeoLocation
    {
        if ($ip === null || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $reader = $this->reader();
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable $e) {
            Log::warning('GeoIP lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        $countryCode = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;
        if (! is_string($countryCode) || strlen($countryCode) !== 2) {
            return null;
        }

        $city = $record['city']['names']['en'] ?? null;

        return new GeoLocation(
            strtoupper($countryCode),
            is_string($city) && $city !== '' ? Str::limit($city, 97) : null,
        );
    }

    private function reader(): ?Reader
    {
        if ($this->opened) {
            return $this->reader;
        }

        $this->opened = true;

        if (! is_file($this->databasePath)) {
            return null;
        }

        try {
            $this->reader = new Reader($this->databasePath);
        } catch (Throwable $e) {
            Log::warning('GeoIP database could not be opened', ['error' => $e->getMessage()]);
        }

        return $this->reader;
    }
}
