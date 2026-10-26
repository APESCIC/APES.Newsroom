<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Support\Integrations\IntegrationDefinitions;

class OpenAiCheck extends ManagedKeyCheck
{
    public function provider(): string
    {
        return IntegrationDefinitions::OPENAI;
    }

    protected function requiredKeys(): array
    {
        return ['api_key'];
    }

    public function run(array $overrides = []): CheckResult
    {
        $response = $this->http()
            ->withToken((string) $this->value('api_key', $overrides))
            ->get('https://api.openai.com/v1/models');

        if ($response->status() === 401) {
            return CheckResult::fail('OpenAI rejected the API key.');
        }

        if ($response->status() === 429) {
            return CheckResult::warn('API key accepted, but the account is rate limited or out of quota.');
        }

        if (! $response->successful()) {
            return CheckResult::fail('OpenAI API returned HTTP '.$response->status().'.');
        }

        return config('newsroom.ai_assist.enabled')
            ? CheckResult::pass('API key accepted.')
            : CheckResult::warn('API key accepted, but AI assist is turned off (NEWSROOM_AI_ASSIST_ENABLED).');
    }
}
