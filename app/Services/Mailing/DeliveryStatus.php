<?php

namespace App\Services\Mailing;

use App\Models\MailEvent;
use App\Models\Suppression;
use Illuminate\Support\Str;

/**
 * Why an address is suppressed and its latest delivery problem, for staff
 * and admin views of subscribers and people.
 */
class DeliveryStatus
{
    /**
     * @param  list<string>  $emails
     * @return array<string, array{reason: string, label: string, source: string|null, since: string|null, last_event: array{type: string, at: string|null, reason: string|null}|null}>
     */
    public function forEmails(array $emails): array
    {
        $emails = array_values(array_unique(array_map(fn (string $email) => Str::lower(trim($email)), $emails)));
        if ($emails === []) {
            return [];
        }

        $problems = MailEvent::query()
            ->whereIn('recipient', $emails)
            ->whereIn('type', [MailEvent::FAILED, MailEvent::COMPLAINED, MailEvent::UNSUBSCRIBED])
            ->orderByDesc('occurred_at')
            ->get(['recipient', 'type', 'severity', 'reason', 'occurred_at'])
            ->unique('recipient')
            ->keyBy('recipient');

        return Suppression::query()
            ->whereIn('email', $emails)
            ->get()
            ->mapWithKeys(function (Suppression $suppression) use ($problems) {
                $event = $problems->get($suppression->email);

                return [$suppression->email => [
                    'reason' => $suppression->reason,
                    'label' => Suppression::reasonLabel($suppression->reason),
                    'source' => $suppression->source,
                    'since' => $suppression->created_at?->toIso8601String(),
                    'last_event' => $event === null ? null : [
                        'type' => $event->type === MailEvent::FAILED ? ($event->severity === 'temporary' ? 'temporary_failure' : 'bounce') : $event->type,
                        'at' => $event->occurred_at?->toIso8601String(),
                        'reason' => $event->reason,
                    ],
                ]];
            })
            ->all();
    }

    /**
     * @return array{reason: string, label: string, source: string|null, since: string|null, last_event: array{type: string, at: string|null, reason: string|null}|null}|null
     */
    public function forEmail(string $email): ?array
    {
        return $this->forEmails([$email])[Str::lower(trim($email))] ?? null;
    }
}
