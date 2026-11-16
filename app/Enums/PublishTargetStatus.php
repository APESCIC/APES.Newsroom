<?php

namespace App\Enums;

enum PublishTargetStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Scheduled => 'Scheduled',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Not sending',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Sent, self::Failed, self::Skipped, self::Cancelled => true,
            self::Pending, self::Scheduled, self::Sending => false,
        };
    }

    public function wantsSend(): bool
    {
        return match ($this) {
            self::Pending, self::Scheduled, self::Sending, self::Sent, self::Failed => true,
            self::Skipped, self::Cancelled => false,
        };
    }
}
