<?php

namespace App\Console\Commands;

use App\Enums\SendingDomainStatus;
use App\Models\EmailSendingDomain;
use App\Services\Mail\MailgunDomainService;
use App\Services\Mail\SendingDomainChecker;
use Illuminate\Console\Command;

class CheckSendingDomainsCommand extends Command
{
    protected $signature = 'mailing:check-sending-domains {--domain= : Only check this domain}';

    protected $description = 'Re-check approved sending domains against Mailgun (read-only) and DNS, and store the result';

    public function handle(SendingDomainChecker $checker, MailgunDomainService $mailgun): int
    {
        if (! $mailgun->isConfigured()) {
            $this->warn('No Mailgun API key is set (Admin → Settings → Integrations or MAILGUN_API_KEY); statuses will not change.');
        }

        $domains = EmailSendingDomain::query()
            ->enabled()
            ->when($this->option('domain'), fn ($query, $domain) => $query->where('domain', strtolower((string) $domain)))
            ->orderBy('domain')
            ->get();

        if ($domains->isEmpty()) {
            $this->error('No matching enabled sending domains.');

            return self::FAILURE;
        }

        $rows = [];
        $senderDomainFailing = false;
        foreach ($domains as $domain) {
            $result = $checker->check($domain);
            $domain->refresh();
            $senderDomainFailing = $senderDomainFailing
                || ($domain->status === SendingDomainStatus::Failed && $domain->isUsedAsSender());

            $rows[] = [
                $domain->domain,
                $result['mailgun_domain'],
                $domain->status->value,
                $this->flag($result['checks']['spf']),
                $this->flag($result['checks']['dkim']),
                $this->flag($result['checks']['dmarc']),
                $result['message'],
            ];
        }

        $this->table(['Domain', 'Mailgun domain', 'Status', 'SPF', 'DKIM', 'DMARC', 'Detail'], $rows);

        return $senderDomainFailing ? self::FAILURE : self::SUCCESS;
    }

    private function flag(bool $value): string
    {
        return $value ? 'ok' : 'fail';
    }
}
