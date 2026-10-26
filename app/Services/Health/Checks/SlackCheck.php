<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Support\Integrations\IntegrationDefinitions;

class SlackCheck extends ManagedKeyCheck
{
    /** Slack error codes that are safe to show (they never contain token material). */
    private const KNOWN_ERRORS = ['invalid_auth', 'not_authed', 'account_inactive', 'token_revoked', 'token_expired', 'missing_scope'];

    public function provider(): string
    {
        return IntegrationDefinitions::SLACK;
    }

    protected function requiredKeys(): array
    {
        return ['bot_token'];
    }

    public function run(array $overrides = []): CheckResult
    {
        $response = $this->http()
            ->withToken((string) $this->value('bot_token', $overrides))
            ->asForm()
            ->post('https://slack.com/api/auth.test');

        if (! $response->successful()) {
            return CheckResult::fail('Slack API returned HTTP '.$response->status().'.');
        }

        if ($response->json('ok') !== true) {
            $error = (string) $response->json('error');

            return CheckResult::fail(in_array($error, self::KNOWN_ERRORS, true)
                ? "Slack rejected the bot token ({$error})."
                : 'Slack rejected the bot token.');
        }

        if (blank($this->value('default_channel', $overrides))) {
            return CheckResult::warn('Bot token accepted, but no channel is set.');
        }

        return CheckResult::pass('Bot token accepted.');
    }
}
