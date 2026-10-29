<?php

namespace App\Services\Mailing;

use App\Models\MailingContact;
use App\Services\Mail\MailTransports;
use Illuminate\Support\Str;

/**
 * Whether an email to a reader may track opens and clicks. Open tracking is
 * opt-in (a tracking pixel needs consent under PECR); click tracking is on
 * unless the reader opts out. A sending domain can switch either off. When
 * newsletters go through the Mailgun API, Mailgun tracks; otherwise the
 * Newsroom's own signed links do, never both.
 */
class TrackingPolicy
{
    public function __construct(private readonly MailTransports $transports) {}

    /**
     * @return array{opens: bool, clicks: bool, provider: 'mailgun'|'newsroom'}
     */
    public function forRecipient(string $email, ?string $fromAddress): array
    {
        $contact = MailingContact::query()->where('email', Str::lower(trim($email)))->first();
        $domain = filled($fromAddress) ? SenderDomains::find((string) $fromAddress) : null;

        return [
            'opens' => $contact?->open_tracking_consented_at !== null && ($domain?->track_opens ?? true),
            'clicks' => $contact?->click_tracking_opted_out_at === null && ($domain?->track_clicks ?? true),
            'provider' => $this->transports->effectiveNewsletterTransport() === MailTransports::MAILGUN_API ? 'mailgun' : 'newsroom',
        ];
    }
}
