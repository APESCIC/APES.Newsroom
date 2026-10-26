<?php

namespace App\Services\Mailing;

use Illuminate\Support\Str;

class SenderDomains
{
    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        $configured = config('mailing.sender_domains', []);
        $domains = is_array($configured) ? $configured : explode(',', (string) $configured);

        return array_values(array_unique(array_filter(array_map(
            fn ($domain) => Str::lower(trim((string) $domain)),
            $domains,
        ))));
    }

    public static function domainOf(string $email): string
    {
        return Str::lower(Str::after(trim($email), '@'));
    }
}
