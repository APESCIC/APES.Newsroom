<?php

namespace App\Services\Mailing;

use App\Enums\SendingDomainStatus;
use App\Models\EmailSendingDomain;

/**
 * Approved sending domains that newsletters use as their From domain but
 * that failed their last Mailgun/DNS check. Sends from them are blocked.
 */
class SendingDomainAlerts
{
    /**
     * @return list<array{domain: string, message: string|null, newsletters: list<string>}>
     */
    public function failing(): array
    {
        return EmailSendingDomain::query()
            ->where('status', SendingDomainStatus::Failed->value)
            ->orderBy('domain')
            ->get()
            ->map(fn (EmailSendingDomain $domain) => [
                'domain' => $domain->domain,
                'message' => $domain->last_check['message'] ?? null,
                'newsletters' => collect($domain->newslettersUsing())
                    ->filter(fn (array $newsletter) => ! $newsletter['archived'] && in_array('from', $newsletter['uses'], true))
                    ->pluck('name')
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $alert) => $alert['newsletters'] !== [])
            ->values()
            ->all();
    }
}
