<?php

namespace App\Console\Commands;

use App\Models\EmailSendingDomain;
use App\Models\MailEvent;
use App\Services\Mail\MailEventRecorder;
use App\Services\Mail\MailgunDomainService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copies each sending domain's Mailgun bounce, complaint and unsubscribe
 * lists into the Newsroom's suppressions. Read-only on Mailgun; safe to
 * rerun because each list entry is stored once.
 */
class SyncMailgunSuppressionsCommand extends Command
{
    protected $signature = 'mailing:sync-mailgun-suppressions {--domain= : Only sync this approved domain}';

    protected $description = 'Import Mailgun bounces, complaints and unsubscribes for each sending domain into suppressions';

    /** Mailgun list => stored event type and severity. */
    private const LISTS = [
        'bounces' => [MailEvent::FAILED, 'permanent'],
        'complaints' => [MailEvent::COMPLAINED, null],
        'unsubscribes' => [MailEvent::UNSUBSCRIBED, null],
    ];

    public function handle(MailgunDomainService $mailgun, MailEventRecorder $recorder): int
    {
        if (! $mailgun->isConfigured()) {
            $this->warn('The Mailgun API key is not configured, so there is nothing to sync.');

            return self::SUCCESS;
        }

        $domains = EmailSendingDomain::query()->enabled()->orderBy('domain')
            ->when($this->option('domain'), fn ($query, $domain) => $query->where('domain', Str::lower((string) $domain)))
            ->get();

        $failed = false;
        foreach ($domains as $domain) {
            $mailgunDomain = $mailgun->mailgunDomainFor($domain->domain, $domain->mailgun_domain);
            foreach (self::LISTS as $list => [$type, $severity]) {
                try {
                    $added = $this->syncList($mailgun, $recorder, $mailgunDomain, $list, $type, $severity);
                    $this->line("{$mailgunDomain} {$list}: {$added} new.");
                } catch (RuntimeException $exception) {
                    $failed = true;
                    $this->error("{$mailgunDomain} {$list}: {$exception->getMessage()}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function syncList(MailgunDomainService $mailgun, MailEventRecorder $recorder, string $mailgunDomain, string $list, string $type, ?string $severity): int
    {
        $added = 0;
        foreach ($mailgun->suppressionList($mailgunDomain, $list) as $item) {
            $address = Str::lower(trim((string) ($item['address'] ?? '')));
            if ($address === '' || ! str_contains($address, '@')) {
                continue;
            }

            $event = $recorder->record([
                'event_id' => "mailgun-list:{$list}:{$mailgunDomain}:{$address}",
                'source' => MailEvent::SOURCE_MAILGUN,
                'type' => $type,
                'severity' => $severity,
                'recipient' => $address,
                'domain' => $mailgunDomain,
                'reason' => filled($item['error'] ?? null) ? Str::limit(trim(($item['code'] ?? '').' '.$item['error']), 1000) : "On the Mailgun {$list} list.",
                'occurred_at' => $this->createdAt($item['created_at'] ?? null),
            ]);
            $added += $event !== null ? 1 : 0;
        }

        return $added;
    }

    private function createdAt(mixed $value): CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse((string) $value) : CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }
}
