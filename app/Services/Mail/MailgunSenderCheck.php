<?php

namespace App\Services\Mail;

use App\Models\Newsletter;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\SenderDomains;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Read-only check that a newsletter sender can send through Mailgun.
 * Mailgun verifies domains, not addresses, so the check is per sender domain.
 */
class MailgunSenderCheck
{
    public const UNVERIFIED = 'unverified';

    public const UNKNOWN = 'unknown';

    /** @var array<string, array<string, mixed>> */
    private array $domainCache = [];

    public function __construct(private readonly DnsTxtResolver $dns) {}

    public function isConfigured(): bool
    {
        return filled(config('services.mailgun.secret'));
    }

    /**
     * Run the check and store the result on the newsletter.
     *
     * @return array<string, mixed>
     */
    public function verify(Newsletter $newsletter): array
    {
        $result = $this->check($newsletter);
        $newsletter->forceFill(['sender_verification' => $result])->save();

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

        $senderDomain = SenderDomains::domainOf($senderEmail);
        if (! in_array($senderDomain, SenderDomains::allowed(), true)) {
            return [...$base, 'status' => self::UNVERIFIED, 'domain' => $senderDomain, 'message' => 'The sender domain is not an approved sending domain.'];
        }

        if (! $this->isConfigured()) {
            return [...$base, 'status' => self::UNKNOWN, 'domain' => $senderDomain, 'message' => 'The Mailgun API is not configured, so the sender cannot be checked.'];
        }

        $mailgunDomain = Str::lower((string) (config('services.mailgun.domain') ?: $senderDomain));
        if ($mailgunDomain !== $senderDomain && ! Str::endsWith($mailgunDomain, '.'.$senderDomain)) {
            return [...$base, 'status' => self::UNVERIFIED, 'domain' => $senderDomain, 'mailgun_domain' => $mailgunDomain, 'message' => 'The sender domain does not match the Mailgun sending domain.'];
        }

        $mailgun = $this->mailgunDomain($mailgunDomain);
        $dmarc = $this->hasDmarc($senderDomain);

        $checks = [
            'mailgun_state' => $mailgun['state'] ?? null,
            'spf' => $mailgun['spf'] ?? false,
            'dkim' => $mailgun['dkim'] ?? false,
            'dmarc' => $dmarc,
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

    /**
     * @return array{state?: string|null, spf?: bool, dkim?: bool, error?: string}
     */
    private function mailgunDomain(string $domain): array
    {
        if (isset($this->domainCache[$domain])) {
            return $this->domainCache[$domain];
        }

        $endpoint = trim((string) config('services.mailgun.endpoint', 'api.eu.mailgun.net'), '/');

        try {
            $response = Http::withBasicAuth('api', (string) config('services.mailgun.secret'))
                ->acceptJson()
                ->timeout(10)
                ->get('https://'.$endpoint.'/v4/domains/'.rawurlencode($domain));
        } catch (ConnectionException) {
            return $this->domainCache[$domain] = ['error' => 'Could not reach the Mailgun API.'];
        }

        if ($response->status() === 404) {
            return $this->domainCache[$domain] = ['state' => 'missing', 'spf' => false, 'dkim' => false];
        }

        if (! $response->successful()) {
            return $this->domainCache[$domain] = ['error' => 'Mailgun API returned HTTP '.$response->status().'.'];
        }

        $records = collect($response->json('sending_dns_records', []));
        $txtValid = fn (callable $match) => $records->contains(fn ($record) => Str::upper((string) ($record['record_type'] ?? '')) === 'TXT'
            && ($record['valid'] ?? null) === 'valid'
            && $match($record));

        return $this->domainCache[$domain] = [
            'state' => $response->json('domain.state'),
            'spf' => $txtValid(fn ($record) => Str::startsWith(Str::lower((string) ($record['value'] ?? '')), 'v=spf1')),
            'dkim' => $txtValid(fn ($record) => Str::contains(Str::lower((string) ($record['name'] ?? '')), '._domainkey.')),
        ];
    }

    private function hasDmarc(string $domain): bool
    {
        foreach ($this->dns->txt('_dmarc.'.$domain) as $record) {
            if (Str::startsWith(Str::lower(trim($record, ' "')), 'v=dmarc1')) {
                return true;
            }
        }

        return false;
    }
}
