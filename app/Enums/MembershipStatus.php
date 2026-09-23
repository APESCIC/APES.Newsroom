<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Free = 'free';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    public function isPaying(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free member',
            self::Active => 'Paid member',
            self::PastDue => 'Past due',
            self::Canceled => 'Canceled',
        };
    }
}
