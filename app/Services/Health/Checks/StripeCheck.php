<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Support\Str;

class StripeCheck extends ManagedKeyCheck
{
    private const API = 'https://api.stripe.com/v1';

    public function provider(): string
    {
        return IntegrationDefinitions::STRIPE;
    }

    protected function requiredKeys(): array
    {
        return ['secret'];
    }

    public function run(array $overrides = []): CheckResult
    {
        $secret = (string) $this->value('secret', $overrides);
        $mode = Str::contains($secret, '_live_') ? 'Live mode' : 'Test mode';
        $request = $this->http()->withBasicAuth($secret, '');

        $account = $request->get(self::API.'/account');

        if ($account->status() === 401) {
            return CheckResult::fail('Stripe rejected the secret key.');
        }

        if ($account->status() === 403) {
            return CheckResult::warn("{$mode}. The key works but cannot read the account (restricted key).");
        }

        if (! $account->successful()) {
            return CheckResult::fail('Stripe API returned HTTP '.$account->status().'.');
        }

        $endpoints = $request->get(self::API.'/webhook_endpoints', ['limit' => 100]);

        if (! $endpoints->successful()) {
            return CheckResult::warn("{$mode}. Secret key accepted, but webhook endpoints could not be listed.");
        }

        $hasWebhook = collect($endpoints->json('data', []))->contains(
            fn ($endpoint) => is_array($endpoint)
                && ($endpoint['status'] ?? null) === 'enabled'
                && Str::endsWith(rtrim((string) ($endpoint['url'] ?? ''), '/'), '/stripe/webhook'),
        );

        if (! $hasWebhook) {
            return CheckResult::warn("{$mode}. Secret key accepted, but no enabled webhook endpoint points at /stripe/webhook.");
        }

        if (blank($this->value('webhook_secret', $overrides))) {
            return CheckResult::warn("{$mode}. Webhook endpoint found, but no webhook signing secret is set.");
        }

        return CheckResult::pass("{$mode}. Secret key accepted and the webhook endpoint is enabled.");
    }
}
