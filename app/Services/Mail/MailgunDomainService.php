<?php

namespace App\Services\Mail;

use App\Services\Integrations\IntegrationSecrets;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Read-only Mailgun domain lookups. Only GET /v4/domains/{domain} is called:
 * domains are added and verified in the Mailgun dashboard, never from here.
 */
class MailgunDomainService
{
    public const DMARC_RECOMMENDATION = 'v=DMARC1; p=quarantine; adkim=r; aspf=r';

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly IntegrationSecrets $secrets,
        private readonly DnsTxtResolver $dns,
    ) {}

    public function isConfigured(): bool
    {
        return $this->secrets->configured(IntegrationDefinitions::MAILGUN, 'api_key');
    }

    /**
     * The Mailgun sending domain configured under Integrations, if any.
     */
    public function configuredDomain(): ?string
    {
        $domain = $this->secrets->get(IntegrationDefinitions::MAILGUN, 'domain');

        return filled($domain) ? Str::lower(trim((string) $domain)) : null;
    }

    /**
     * The Mailgun domain to look up for an approved domain: an explicit
     * override, else the configured Mailgun domain when it is the same domain
     * or a subdomain of it (for example mg.apesnews.org.uk), else the domain.
     */
    public function mailgunDomainFor(string $domain, ?string $override = null): string
    {
        if (filled($override)) {
            return Str::lower(trim((string) $override));
        }

        $configured = $this->configuredDomain();
        if ($configured !== null && ($configured === $domain || Str::endsWith($configured, '.'.$domain))) {
            return $configured;
        }

        return $domain;
    }

    /**
     * @return array{found: bool, state?: string|null, spf?: bool, dkim?: bool, records?: list<array{purpose: string, type: string, name: string, value: string, valid: bool}>, error?: string}
     */
    public function lookup(string $mailgunDomain): array
    {
        if (isset($this->cache[$mailgunDomain])) {
            return $this->cache[$mailgunDomain];
        }

        if (! $this->isConfigured()) {
            return $this->cache[$mailgunDomain] = ['found' => false, 'error' => 'The Mailgun API key is not configured under Integrations.'];
        }

        $endpoint = trim($this->secrets->get(IntegrationDefinitions::MAILGUN, 'endpoint') ?? 'api.eu.mailgun.net', '/');

        try {
            $response = Http::withBasicAuth('api', (string) $this->secrets->get(IntegrationDefinitions::MAILGUN, 'api_key'))
                ->acceptJson()
                ->timeout(10)
                ->get('https://'.$endpoint.'/v4/domains/'.rawurlencode($mailgunDomain));
        } catch (ConnectionException) {
            return $this->cache[$mailgunDomain] = ['found' => false, 'error' => 'Could not reach the Mailgun API.'];
        }

        if ($response->status() === 404) {
            return $this->cache[$mailgunDomain] = ['found' => false, 'state' => 'missing', 'spf' => false, 'dkim' => false, 'records' => []];
        }

        if (! $response->successful()) {
            return $this->cache[$mailgunDomain] = ['found' => false, 'error' => 'Mailgun API returned HTTP '.$response->status().'.'];
        }

        $records = [];
        foreach ((array) $response->json('sending_dns_records', []) as $record) {
            $records[] = $this->record($record, false);
        }
        foreach ((array) $response->json('receiving_dns_records', []) as $record) {
            $records[] = $this->record($record, true);
        }

        $txtValid = fn (string $purpose) => collect($records)->contains(fn (array $record) => $record['purpose'] === $purpose && $record['valid']);

        return $this->cache[$mailgunDomain] = [
            'found' => true,
            'state' => $response->json('domain.state'),
            'spf' => $txtValid('SPF'),
            'dkim' => $txtValid('DKIM'),
            'records' => $records,
        ];
    }

    /**
     * The domain's DMARC TXT record, or null when there is none.
     */
    public function dmarcRecord(string $domain): ?string
    {
        foreach ($this->dns->txt('_dmarc.'.$domain) as $record) {
            $value = trim($record, ' "');
            if (Str::startsWith(Str::lower($value), 'v=dmarc1')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{purpose: string, type: string, name: string, value: string, valid: bool}
     */
    private function record(array $record, bool $receiving): array
    {
        $type = Str::upper((string) ($record['record_type'] ?? ''));
        $name = (string) ($record['name'] ?? '');
        $value = (string) ($record['value'] ?? '');

        $purpose = match (true) {
            $receiving => 'MX',
            $type === 'TXT' && Str::startsWith(Str::lower($value), 'v=spf1') => 'SPF',
            $type === 'TXT' && Str::contains(Str::lower($name), '._domainkey.') => 'DKIM',
            $type === 'CNAME' => 'Tracking',
            default => $type,
        };

        return [
            'purpose' => $purpose,
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'valid' => ($record['valid'] ?? null) === 'valid',
        ];
    }
}
