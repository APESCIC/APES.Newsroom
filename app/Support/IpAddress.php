<?php

namespace App\Support;

final class IpAddress
{
    /**
     * Enough of an address for a user to recognise their own network
     * without showing the full IP: 203.0.113.x or 2001:db8:85a3:x.
     */
    public static function mask(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.x';
        }

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($ip) : false;

        if ($packed === false) {
            return null;
        }

        $groups = array_map(
            fn (string $pair) => ltrim($pair, '0') ?: '0',
            str_split(bin2hex(substr($packed, 0, 6)), 4),
        );

        return implode(':', $groups).':x';
    }
}
