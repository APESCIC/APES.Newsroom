<?php

namespace App\Enums;

enum ContentVisibility: string
{
    case Public = 'public';
    case Members = 'members';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Members => 'Members only',
            self::Paid => 'Paid members only',
        };
    }
}
