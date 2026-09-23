<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if (! $delivery || ! $delivery->endpoint) {
            return;
        }

        if ($delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        if (! $delivery->endpoint->is_active) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_FAILED,
                'last_error' => 'endpoint_inactive',
            ])->save();

            return;
        }

        $delivery->increment('attempts');
        $delivery->refresh();

        $json = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $delivery->endpoint->secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Newsroom-Event' => $delivery->event,
                    'X-Newsroom-Delivery' => $delivery->delivery_uuid,
                    'X-Newsroom-Signature' => 'sha256='.$signature,
                ])
                ->withBody($json, 'application/json')
                ->post($delivery->endpoint->url);

            if ($response->successful()) {
                $delivery->forceFill([
                    'status' => WebhookDelivery::STATUS_DELIVERED,
                    'response_status' => $response->status(),
                    'last_error' => null,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                ])->save();

                return;
            }

            $this->scheduleRetry($delivery, 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500), $response->status());
        } catch (Throwable $e) {
            Log::warning('Webhook delivery attempt failed', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
            $this->scheduleRetry($delivery, $e->getMessage(), null);
        }
    }

    private function scheduleRetry(WebhookDelivery $delivery, string $error, ?int $status): void
    {
        $maxAttempts = 5;
        $attempts = (int) $delivery->attempts;

        if ($attempts >= $maxAttempts) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_FAILED,
                'response_status' => $status,
                'last_error' => $error,
                'next_attempt_at' => null,
            ])->save();

            return;
        }

        $delaySeconds = [60, 300, 900, 3600, 7200][$attempts - 1] ?? 7200;

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_PENDING,
            'response_status' => $status,
            'last_error' => $error,
            'next_attempt_at' => now()->addSeconds($delaySeconds),
        ])->save();

        self::dispatch($delivery->id)->delay(now()->addSeconds($delaySeconds));
    }
}
