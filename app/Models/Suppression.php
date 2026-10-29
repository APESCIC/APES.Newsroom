<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'reason', 'source', 'notes'])]
class Suppression extends Model
{
    public const BOUNCED = 'bounced';

    public const COMPLAINED = 'complained';

    public const UNSUBSCRIBED_MAILGUN = 'unsubscribed_mailgun';

    public const REPEATED_SOFT_BOUNCE = 'repeated_soft_bounce';

    /**
     * Reasons set by delivery events rather than by people.
     */
    public const DELIVERY_REASONS = [self::BOUNCED, self::COMPLAINED, self::UNSUBSCRIBED_MAILGUN, self::REPEATED_SOFT_BOUNCE];

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::BOUNCED => 'Bounced',
            self::COMPLAINED => 'Marked as spam',
            self::UNSUBSCRIBED_MAILGUN => 'Unsubscribed through the email provider',
            self::REPEATED_SOFT_BOUNCE => 'Repeated temporary failures',
            'staff' => 'Suppressed by staff',
            'unsubscribe_all', 'unsubscribe_without_contact' => 'Unsubscribed from everything',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }
}
