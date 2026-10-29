<?php

namespace App\Services\Mail;

use App\Models\EmailSendingDomain;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\SenderDomains;
use Illuminate\Support\Str;

/**
 * Read-only check that a newsletter sender can send through Mailgun.
 * Mailgun verifies domains, not addresses, so the check is per sender domain.
 */
class MailgunSenderCheck
{
    public const UNVERIFIED = 'unverified';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly MailgunDomainService $mailgun,
        private readonly SendingDomainChecker $domains,
    ) {}

    public function isConfigured(): bool
    {
        return $this->mailgun->isConfigured();
    }

    /**
     * Run the check and store the result on the newsletter. When Mailgun was
     * reached, the approved sending domain is re-checked too so its status
     * matches what the sender check just saw.
     *
     * @return array<string, mixed>
     */
    public function verify(Newsletter $newsletter): array
    {
        $result = $this->check($newsletter);
        $newsletter->forceFill(['sender_verification' => $result])->save();

        if (isset($result['checks']) && $result['status'] !== self::UNKNOWN) {
            $domain = EmailSendingDomain::query()->enabled()->where('domain', $result['domain'])->first();
            if ($domain) {
                $actor = auth()->user();
                $this->domains->check($domain, $actor instanceof User ? $actor : null);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function check(Newsletter $newsletter): array
    {
        $senderEmail = Str::lower((string) $newsletter->sender_email);
        $base = [
            'sender_email' => $senderEmail,
            'checked_at' => now()->toIso8601String(),
        ];

        if ($senderEmail === '') {
            return [...$base, 'status' => self::UNVERIFIED, 'message' => 'No sender email is set.'];
        }

        $approved = SenderDomains::find($senderEmail);
        $senderDomain = $approved?->domain ?? SenderDomains::domainOf($senderEmail);
        if ($approved === null || ! $approved->isEnabled()) {
            return [...$base, 'status' => self::UNVERIFIED, 'domain' => $senderDomain, 'message' => 'The sender domain is not an approved sending domain.'];
        }

        if (! $this->isConfigured()) {
            return [...$base, 'status' => self::UNKNOWN, 'domain' => $senderDomain, 'message' => 'The Mailgun API is not configured, so the sender cannot be checked.'];
        }

        $mailgunDomain = $this->mailgun->mailgunDomainFor($approved->domain, $approved->mailgun_domain);
        $mailgun = $this->mailgun->lookup($mailgunDomain);

        $checks = [
            'mailgun_state' => $mailgun['state'] ?? null,
            'spf' => $mailgun['spf'] ?? false,
            'dkim' => $mailgun['dkim'] ?? false,
            'dmarc' => $this->mailgun->dmarcRecordFor(SenderDomains::domainOf($senderEmail), $approved->domain) !== null,
        ];

        if (isset($mailgun['error'])) {
            return [...$base, 'status' => self::UNKNOWN, 'domain' => $senderDomain, 'mailgun_domain' => $mailgunDomain, 'checks' => $checks, 'message' => $mailgun['error']];
        }

        $verified = $checks['mailgun_state'] === 'active' && $checks['spf'] && $checks['dkim'] && $checks['dmarc'];
        $failing = array_keys(array_filter([
            'Mailgun domain not active' => $checks['mailgun_state'] !== 'active',
            'SPF' => ! $checks['spf'],
            'DKIM' => ! $checks['dkim'],
            'DMARC' => ! $checks['dmarc'],
        ]));

        return [
            ...$base,
            'status' => $verified ? CampaignSender::VERIFIED : self::UNVERIFIED,
            'domain' => $senderDomain,
            'mailgun_domain' => $mailgunDomain,
            'checks' => $checks,
            'message' => $verified ? 'Sender domain is verified in Mailgun.' : 'Failing: '.implode(', ', $failing).'.',
        ];
    }
}
