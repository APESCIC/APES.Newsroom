<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiAssistService
{
    public function enabled(): bool
    {
        return (bool) config('newsroom.ai_assist.enabled')
            && filled(config('newsroom.ai_assist.api_key'));
    }

    /**
     * Suggest draft copy for the editor. Never publishes.
     *
     * @return array{suggestion: string, model: string}
     */
    public function suggest(string $prompt, ?string $context = null): array
    {
        if (! $this->enabled()) {
            throw new \RuntimeException('AI assist is disabled.');
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new \InvalidArgumentException('Prompt is required.');
        }

        $system = 'You are an editorial assistant for APES Newsroom. Suggest concise draft copy for a news article. Do not claim the article is published. Return plain text only.';
        $user = $prompt;
        if (filled($context)) {
            $user .= "\n\nExisting draft context:\n".mb_substr((string) $context, 0, 4000);
        }

        try {
            $response = Http::timeout(30)
                ->withToken((string) config('newsroom.ai_assist.api_key'))
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => (string) config('newsroom.ai_assist.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'temperature' => 0.4,
                    'max_tokens' => 500,
                ]);

            if (! $response->successful()) {
                Log::warning('AI assist request failed', ['status' => $response->status()]);
                throw new \RuntimeException('AI provider request failed.');
            }

            $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            if ($text === '') {
                throw new \RuntimeException('AI provider returned empty suggestion.');
            }

            return [
                'suggestion' => $text,
                'model' => (string) config('newsroom.ai_assist.model', 'gpt-4o-mini'),
            ];
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            Log::warning('AI assist exception', ['error' => $e->getMessage()]);
            throw new \RuntimeException('AI assist unavailable.');
        }
    }
}
