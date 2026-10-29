<?php

namespace App\Services\Mail;

use App\Models\CampaignRecipient;
use App\Models\MailEvent;
use App\Models\Suppression;
use App\Services\Audit\AuditLogger;
use App\Services\Mailing\ConsentService;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Stores delivery and engagement events once (by event id) and applies
 * them: permanent failures, complaints and provider unsubscribes suppress
 * the address, repeated temporary failures suppress it past the limit,
 * and opens and clicks fill in the campaign recipient's stats.
 */
class MailEventRecorder
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record a Mailgun webhook "event-data" object. Returns null for an
     * event already stored or one that is not about a recipient.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordMailgun(array $data): ?MailEvent
    {
        $id = (string) ($data['id'] ?? '');
        $recipient = Str::lower(trim((string) ($data['recipient'] ?? '')));
        $type = (string) ($data['event'] ?? '');
        if ($id === '' || $recipient === '' || $type === '') {
            return null;
        }

        $messageId = trim((string) data_get($data, 'message.headers.message-id', ''), '<> ');
        $recipientId = data_get($data, 'user-variables.campaign_recipient_id');

        return $this->record([
            'event_id' => 'mailgun:'.$id,
            'source' => MailEvent::SOURCE_MAILGUN,
            'type' => $type,
            'severity' => isset($data['severity']) ? (string) $data['severity'] : null,
            'recipient' => $recipient,
            'domain' => self::domainOf((string) data_get($data, 'envelope.sender', data_get($data, 'message.headers.from', ''))),
            'reason' => $this->reasonOf($data),
            'url' => isset($data['url']) ? Str::limit((string) $data['url'], 2000, '') : null,
            'provider_message_id' => $messageId !== '' ? $messageId : null,
            'campaign_recipient_id' => is_numeric($recipientId) ? (int) $recipientId : null,
            'occurred_at' => isset($data['timestamp']) && is_numeric($data['timestamp'])
                ? CarbonImmutable::createFromTimestamp((float) $data['timestamp'])
                : now(),
        ]);
    }

    /**
     * Record an event and apply it, or return null when its id is stored.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): ?MailEvent
    {
        $recipient = $this->campaignRecipient($attributes);
        $attributes['campaign_recipient_id'] = $recipient?->id;

        try {
            $event = MailEvent::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->apply($event, $recipient);

        return $event;
    }

    private function apply(MailEvent $event, ?CampaignRecipient $recipient): void
    {
        $evidence = ['event_id' => $event->event_id, 'source' => $event->source];

        match (true) {
            $event->type === MailEvent::FAILED && $event->severity === 'permanent' => $this->suppress($event, Suppression::BOUNCED, $evidence),
            $event->type === MailEvent::FAILED && $event->severity === 'temporary' => $this->checkSoftBounces($event, $evidence),
            $event->type === MailEvent::COMPLAINED => $this->suppress($event, Suppression::COMPLAINED, $evidence),
            $event->type === MailEvent::UNSUBSCRIBED => $this->suppress($event, Suppression::UNSUBSCRIBED_MAILGUN, $evidence),
            default => null,
        };

        if ($recipient === null) {
            return;
        }

        if ($event->type === MailEvent::OPENED && $recipient->opened_at === null) {
            $recipient->forceFill(['opened_at' => $event->occurred_at])->save();
        }

        if ($event->type === MailEvent::CLICKED) {
            $recipient->forceFill([
                'clicked_at' => $recipient->clicked_at ?? $event->occurred_at,
                'opened_at' => $recipient->opened_at ?? $event->occurred_at,
            ])->save();
        }
    }

    /**
     * Temporary failures on separate sends since the last delivery, within
     * the window; reaching the limit suppresses the address.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function checkSoftBounces(MailEvent $event, array $evidence): void
    {
        $limit = (int) $this->settings->get(SettingDefinitions::MAIL_SOFT_BOUNCE_LIMIT);
        $since = $event->occurred_at->copy()->subDays((int) $this->settings->get(SettingDefinitions::MAIL_SOFT_BOUNCE_WINDOW));

        $lastDelivered = MailEvent::query()
            ->where('recipient', $event->recipient)
            ->where('type', MailEvent::DELIVERED)
            ->where('occurred_at', '>=', $since)
            ->max('occurred_at');
        if ($lastDelivered !== null) {
            $since = CarbonImmutable::parse($lastDelivered);
        }

        $failures = MailEvent::query()
            ->where('recipient', $event->recipient)
            ->where('type', MailEvent::FAILED)
            ->where('severity', 'temporary')
            ->where('occurred_at', '>=', $since)
            ->get(['id', 'provider_message_id'])
            ->map(fn (MailEvent $failure) => $failure->provider_message_id ?? 'event:'.$failure->id)
            ->unique()
            ->count();

        if ($failures >= $limit) {
            $this->suppress($event, Suppression::REPEATED_SOFT_BOUNCE, [...$evidence, 'temporary_failures' => $failures]);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function suppress(MailEvent $event, string $reason, array $evidence): void
    {
        if (! $this->consent->suppress($event->recipient, $reason, $event->source, $event->reason, $evidence)) {
            return;
        }

        $this->audit->record(null, 'mailing.suppressed_by_delivery_event', null, [
            'email' => $event->recipient,
            'reason' => $reason,
            'event_type' => $event->type,
            'event_id' => $event->event_id,
            'source' => $event->source,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function campaignRecipient(array $attributes): ?CampaignRecipient
    {
        $id = $attributes['campaign_recipient_id'] ?? null;
        $recipient = $id !== null ? CampaignRecipient::query()->find($id) : null;
        if ($recipient !== null && Str::lower($recipient->email) === $attributes['recipient']) {
            return $recipient;
        }

        $messageId = $attributes['provider_message_id'] ?? null;

        return $messageId !== null
            ? CampaignRecipient::query()->where('provider_message_id', $messageId)->where('email', $attributes['recipient'])->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reasonOf(array $data): ?string
    {
        $parts = array_filter([
            data_get($data, 'delivery-status.code'),
            data_get($data, 'delivery-status.description') ?: data_get($data, 'delivery-status.message'),
            $data['reason'] ?? null,
        ], fn ($part) => filled($part));

        return $parts === [] ? null : Str::limit(implode(' ', array_map('strval', $parts)), 1000);
    }

    private static function domainOf(string $address): ?string
    {
        if (preg_match('/@([A-Za-z0-9.-]+)/', $address, $match) !== 1) {
            return null;
        }

        return Str::lower($match[1]);
    }
}
