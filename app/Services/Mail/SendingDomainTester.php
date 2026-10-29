<?php

namespace App\Services\Mail;

use App\Enums\SenderAddressPurpose;
use App\Mail\SendingDomainTestMail;
use App\Models\EmailSenderAddress;
use App\Models\EmailSendingDomain;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Mailing\DefaultReplyTo;
use App\Services\Mailing\SenderDomains;
use Illuminate\Http\Request;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a test email synchronously through a sending domain's own Mailgun
 * domain, re-checks the domain live, and stores and audits the outcome.
 */
class SendingDomainTester
{
    public function __construct(
        private readonly MailgunDomainService $mailgun,
        private readonly SendingDomainChecker $checker,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{ok: bool, from: string, recipients: list<string>, mailgun_domain: string, message_id: string|null, error: string|null, warning: string|null, status: string, checks: array<string, mixed>, check_message: string, tested_at: string}
     */
    public function send(EmailSendingDomain $domain, ?EmailSenderAddress $address, User $actor, ?string $extraRecipient, ?Request $request = null): array
    {
        $from = $address?->address ?? $this->defaultFrom($domain);
        $mailgunDomain = $this->mailgun->mailgunDomainFor($domain->domain, $domain->mailgun_domain);
        $recipients = array_values(array_unique(array_filter([strtolower((string) $actor->email), $extraRecipient !== null ? strtolower($extraRecipient) : null])));

        $check = $this->checker->check($domain, $actor);
        $domain->refresh();

        $warning = implode(' ', array_filter([
            $domain->isVerified()
                ? null
                : "{$domain->domain} is not verified ({$domain->status->label()}). The test was still attempted, but it may be refused or fail DMARC.",
            $this->mailgun->alignmentProblem(SenderDomains::domainOf($from), $domain->domain, $mailgunDomain),
        ])) ?: null;

        [$messageId, $error] = $this->deliver($domain, $from, $mailgunDomain, $recipients, $actor);

        $result = [
            'ok' => $error === null,
            'from' => $from,
            'recipients' => $recipients,
            'mailgun_domain' => $mailgunDomain,
            'message_id' => $messageId,
            'error' => $error,
            'warning' => $warning,
            'status' => $domain->status->value,
            'checks' => $check['checks'],
            'check_message' => $check['message'],
            'tested_at' => now()->toIso8601String(),
        ];

        $stored = ['last_tested_at' => now(), 'last_test_result' => $result];
        $domain->forceFill($stored)->save();
        $address?->forceFill($stored)->save();

        $this->audit->record($actor, 'email_sending_domain.test_sent', $address ?? $domain, [
            'domain' => $domain->domain,
            'from' => $from,
            'recipients' => $recipients,
            'outcome' => $result['ok'] ? 'sent' : 'failed',
            'message_id' => $messageId,
            'error' => $error,
        ], $request);

        return $result;
    }

    /**
     * @param  list<string>  $recipients
     * @return array{0: string|null, 1: string|null} Message ID and error.
     */
    private function deliver(EmailSendingDomain $domain, string $from, string $mailgunDomain, array $recipients, User $actor): array
    {
        if (! $domain->isEnabled()) {
            return [null, "{$domain->domain} is disabled. Enable it before sending a test."];
        }

        if (! $this->mailgun->isConfigured()) {
            return [null, 'The Mailgun API key is not configured under Integrations, so no test could be sent.'];
        }

        $replyTo = DefaultReplyTo::usable();

        try {
            $pending = Mail::mailer('mailgun-api')->to($recipients[0]);
            if (count($recipients) > 1) {
                $pending->bcc(array_slice($recipients, 1));
            }
            $sent = $pending->send(new SendingDomainTestMail($from, $mailgunDomain, $actor->name ?? $actor->email, $replyTo));
        } catch (Throwable $exception) {
            return [null, $exception->getMessage()];
        }

        return [$sent instanceof SentMessage ? $sent->getMessageId() : null, null];
    }

    /**
     * A domain-level test uses the domain's first enabled no-reply address,
     * then any enabled address, then noreply@ on the domain.
     */
    private function defaultFrom(EmailSendingDomain $domain): string
    {
        $addresses = $domain->addresses()->enabled()->get();

        return $addresses->firstWhere('purpose', SenderAddressPurpose::Noreply)?->address
            ?? $addresses->first()?->address
            ?? 'noreply@'.$domain->domain;
    }
}
