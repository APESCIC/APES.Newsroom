<?php

namespace App\Services\Import;

use Generator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Streams rows from a Ghost members CSV export (full or newsletter-filtered).
 */
class GhostMembersCsv
{
    /**
     * @return Generator<int, array<string, string|null>>
     */
    public static function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV.');
        }

        try {
            $headers = fgetcsv($handle, escape: '\\');
            if ($headers === false) {
                throw new RuntimeException('CSV is empty.');
            }

            $headers = array_map(
                fn ($header) => Str::of((string) $header)->replaceStart("\u{FEFF}", '')->lower()->trim()->toString(),
                $headers,
            );

            if (! in_array('email', $headers, true)) {
                throw new RuntimeException('CSV must include an email column.');
            }

            while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                if (collect($row)->every(fn ($cell) => trim((string) $cell) === '')) {
                    continue;
                }

                yield array_combine($headers, array_slice(array_pad($row, count($headers), null), 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    public static function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim((string) $value));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 't', 'y'], true);
    }
}
