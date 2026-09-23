<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OutboundWebhookDispatcher
{
    public const EVENT_POST_PUBLISHED = 'post.published';

    public const EVENT_MEMBER_CREATED = 'member.created';

    public const EVENT_SUBSCRIPTION_UPDATED = 'subscription.updated';

    /**
     * @return list<string>
     */
    public static function supportedEvents(): array
    {
        return [
            self::EVENT_POST_PUBLISHED,
            self::EVENT_MEMBER_CREATED,
            self::EVENT_SUBSCRIPTION_UPDATED,
        ];
    }

    /**
     * Queue signed deliveries. Never throws to callers (publish/membership must not block).
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        try {
            if (! in_array($event, self::supportedEvents(), true)) {
                return;
            }

            $body = [
                'event' => $event,
                'occurred_at' => now()->toIso8601String(),
                'data' => $payload,
            ];

            $endpoints = WebhookEndpoint::query()
                ->where('is_active', true)
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->listensFor($event));

            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::query()->create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => $event,
                    'delivery_uuid' => (string) Str::uuid(),
                    'payload' => $body,
                    'status' => WebhookDelivery::STATUS_PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => now(),
                ]);

                DeliverWebhookJob::dispatch($delivery->id);
            }
        } catch (Throwable $e) {
            Log::warning('Outbound webhook dispatch failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
