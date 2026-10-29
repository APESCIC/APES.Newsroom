<?php

namespace App\Enums;

enum SenderAddressPurpose: string
{
    case News = 'news';
    case Noreply = 'noreply';

    public function label(): string
    {
        return match ($this) {
            self::News => 'Newsletters',
            self::Noreply => 'No-reply',
        };
    }
}
