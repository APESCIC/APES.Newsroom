<?php

namespace App\Services\Mail;

use App\Enums\SendingDomainStatus;
use App\Models\EmailSendingDomain;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Re-checks an approved sending domain against Mailgun (read-only) and DNS.
 * A domain becomes verified only when Mailgun reports it active with valid
 * SPF and DKIM records and the domain publishes DMARC. API errors leave the
 * status unchanged so a Mailgun outage never blocks sends on its own.
 */
class SendingDomainChecker
{
    public function __construct(
        private readonly MailgunDomainService $mailgun,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed> The stored last_check payload.
     */
    public function check(EmailSendingDomain $domain, ?User $actor = null): array
    {
        $mailgunDomain = $this->mailgun->mailgunDomainFor($domain->domain, $domain->mailgun_domain);
        $lookup = $this->mailgun->lookup($mailgunDomain);
        $dmarc = $this->mailgun->dmarcRecord($domain->domain);

        $checks = [
            'mailgun_state' => $lookup['state'] ?? null,
            'spf' => $lookup['spf'] ?? false,
            'dkim' => $lookup['dkim'] ?? false,
            'dmarc' => $dmarc !== null,
        ];

        $previous = $domain->status;
        $next = $this->nextStatus($previous, $lookup, $checks);

        $result = [
            'checked_at' => now()->toIso8601String(),
            'mailgun_domain' => $mailgunDomain,
            'found' => $lookup['found'],
            'checks' => $checks,
            'records' => $lookup['records'] ?? [],
            'dmarc' => [
                'name' => '_dmarc.'.$domain->domain,
                'value' => $dmarc,
                'recommendation' => self::dmarcRecommendation(),
            ],
            'error' => $lookup['error'] ?? null,
            'message' => $this->message($lookup, $checks, $mailgunDomain),
        ];

        $domain->forceFill([
            'status' => $next,
            'last_check' => $result,
            'last_checked_at' => now(),
            'verified_at' => $next === SendingDomainStatus::Verified && $previous !== SendingDomainStatus::Verified
                ? now()
                : $domain->verified_at,
        ])->save();

        if ($next !== $previous) {
            $this->audit->record($actor, 'email_sending_domain.status_changed', $domain, [
                'domain' => $domain->domain,
                'old_status' => $previous->value,
                'new_status' => $next->value,
                'message' => $result['message'],
            ]);
        }

        return $result;
    }

    public static function dmarcRecommendation(): string
    {
        return MailgunDomainService::DMARC_RECOMMENDATION;
    }

    /**
     * @param  array<string, mixed>  $lookup
     * @param  array{mailgun_state: mixed, spf: bool, dkim: bool, dmarc: bool}  $checks
     */
    private function nextStatus(SendingDomainStatus $previous, array $lookup, array $checks): SendingDomainStatus
    {
        if ($previous === SendingDomainStatus::Disabled || isset($lookup['error'])) {
            return $previous;
        }

        if (! $lookup['found']) {
            return $previous === SendingDomainStatus::Verified ? SendingDomainStatus::Failed : SendingDomainStatus::Pending;
        }

        $verified = $checks['mailgun_state'] === 'active' && $checks['spf'] && $checks['dkim'] && $checks['dmarc'];

        return $verified ? SendingDomainStatus::Verified : SendingDomainStatus::Failed;
    }

    /**
     * @param  array<string, mixed>  $lookup
     * @param  array{mailgun_state: mixed, spf: bool, dkim: bool, dmarc: bool}  $checks
     */
    private function message(array $lookup, array $checks, string $mailgunDomain): string
    {
        if (isset($lookup['error'])) {
            return 'Could not check Mailgun: '.$lookup['error'].' The status was not changed.';
        }

        if (! $lookup['found']) {
            return "{$mailgunDomain} was not found in Mailgun. Add it in the Mailgun dashboard, publish the DNS records it gives you, then check again. Until then it can only be used as a reply-to domain.";
        }

        $failing = array_keys(array_filter([
            'Mailgun domain not active' => $checks['mailgun_state'] !== 'active',
            'SPF' => ! $checks['spf'],
            'DKIM' => ! $checks['dkim'],
            'DMARC' => ! $checks['dmarc'],
        ]));

        return $failing === []
            ? 'Verified: Mailgun reports the domain active and SPF, DKIM and DMARC all pass.'
            : 'Failing: '.implode(', ', $failing).'.';
    }
}
