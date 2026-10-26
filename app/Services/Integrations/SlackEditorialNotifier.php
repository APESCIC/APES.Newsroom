<?php

namespace App\Services\Integrations;

use App\Models\Post;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlackEditorialNotifier
{
    public function __construct(private readonly IntegrationSecrets $secrets) {}

    public function enabled(): bool
    {
        return $this->secrets->configured(IntegrationDefinitions::SLACK, 'bot_token')
            && $this->secrets->configured(IntegrationDefinitions::SLACK, 'default_channel');
    }

    public function notifyPostPublished(Post $post): void
    {
        $this->send(sprintf(
            '*Published:* <%s|%s> (%s)',
            route('articles.show', $post->slug, absolute: true),
            $post->title,
            $post->channel->label(),
        ));
    }

    public function notifyPostSubmittedForReview(Post $post): void
    {
        $this->send(sprintf(
            '*In review:* %s — <%s|open in staff>',
            $post->title,
            route('staff.posts.edit', $post, absolute: true),
        ));
    }

    private function send(string $text): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $response = Http::timeout(8)
                ->withToken((string) $this->secrets->get(IntegrationDefinitions::SLACK, 'bot_token'))
                ->post('https://slack.com/api/chat.postMessage', [
                    'channel' => $this->secrets->get(IntegrationDefinitions::SLACK, 'default_channel'),
                    'text' => $text,
                ]);

            if (! $response->successful() || ! $response->json('ok')) {
                Log::warning('Slack notify failed', [
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Slack notify exception', ['error' => $e->getMessage()]);
        }
    }
}
