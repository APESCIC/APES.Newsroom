<?php

namespace App\Console\Commands;

use App\Models\Newsletter;
use App\Services\Mail\MailgunSenderCheck;
use App\Services\Mailing\CampaignSender;
use Illuminate\Console\Command;

class VerifyNewsletterSendersCommand extends Command
{
    protected $signature = 'mailing:verify-senders {--newsletter= : Only check the newsletter with this slug}';

    protected $description = 'Check each active newsletter sender against Mailgun (read-only) and store the result';

    public function handle(MailgunSenderCheck $check): int
    {
        if (! $check->isConfigured()) {
            $this->warn('No Mailgun API key is set (Admin → Settings → Integrations or MAILGUN_API_KEY); senders will be recorded as unknown and stay blocked.');
        }

        $newsletters = Newsletter::query()
            ->active()
            ->when($this->option('newsletter'), fn ($query, $slug) => $query->where('slug', $slug))
            ->orderBy('name')
            ->get();

        if ($newsletters->isEmpty()) {
            $this->error('No matching active newsletters.');

            return self::FAILURE;
        }

        $rows = [];
        $allVerified = true;
        foreach ($newsletters as $newsletter) {
            $result = $check->verify($newsletter);
            $allVerified = $allVerified && $result['status'] === CampaignSender::VERIFIED;
            $checks = $result['checks'] ?? [];
            $rows[] = [
                $newsletter->slug,
                $result['sender_email'] ?: '-',
                $result['status'],
                $this->flag($checks['spf'] ?? null),
                $this->flag($checks['dkim'] ?? null),
                $this->flag($checks['dmarc'] ?? null),
                $result['message'],
            ];
        }

        $this->table(['Newsletter', 'Sender', 'Status', 'SPF', 'DKIM', 'DMARC', 'Detail'], $rows);

        return $allVerified ? self::SUCCESS : self::FAILURE;
    }

    private function flag(?bool $value): string
    {
        return match ($value) {
            true => 'ok',
            false => 'fail',
            null => '-',
        };
    }
}
