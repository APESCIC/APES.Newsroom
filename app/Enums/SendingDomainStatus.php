<?php

namespace App\Enums;

enum SendingDomainStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
        };
    }
}
