<?php

namespace App\Services\Integrations;

use App\Models\Post;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlackEditorialNotifier
{
    public function enabled(): bool
    {
        return filled(config('services.slack.notifications.bot_user_oauth_token'))
            && filled(config('services.slack.notifications.channel'));
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
                ->withToken((string) config('services.slack.notifications.bot_user_oauth_token'))
                ->post('https://slack.com/api/chat.postMessage', [
                    'channel' => config('services.slack.notifications.channel'),
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
