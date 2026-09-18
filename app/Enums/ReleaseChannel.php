<?php

namespace App\Enums;

enum ReleaseChannel: string
{
    case Stable = 'stable';
    case Beta = 'beta';

    public function label(): string
    {
        return match ($this) {
            self::Stable => 'Stable',
            self::Beta => 'Beta',
        };
    }
}
