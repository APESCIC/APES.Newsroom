<?php

namespace App\Enums;

enum PublishChannel: string
{
    case Website = 'website';
    case Email = 'email';
    case Social = 'social';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Email => 'Email',
            self::Social => 'Social',
        };
    }
}
