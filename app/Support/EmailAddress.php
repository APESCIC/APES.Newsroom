<?php

namespace App\Support;

use Illuminate\Support\Str;

final class EmailAddress
{
    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Recognisable to its owner without exposing it in full: j***@example.com.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 1).'***@'.$domain;
    }
}
