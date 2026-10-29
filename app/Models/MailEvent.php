<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery or engagement event for one email: from Mailgun's signed
 * webhooks or suppression lists, or the Newsroom's own tracking links.
 * Kept 24 months (newsroom:purge-security-data).
 */
class MailEvent extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_MAILGUN = 'mailgun';

    public const SOURCE_NEWSROOM = 'newsroom';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const OPENED = 'opened';

    public const CLICKED = 'clicked';

    public const COMPLAINED = 'complained';

    public const UNSUBSCRIBED = 'unsubscribed';

    protected $fillable = [
        'event_id',
        'source',
        'type',
        'severity',
        'recipient',
        'domain',
        'reason',
        'url',
        'provider_message_id',
        'campaign_recipient_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CampaignRecipient, $this>
     */
    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }
}
