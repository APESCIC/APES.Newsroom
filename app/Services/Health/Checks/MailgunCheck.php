<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Support\Integrations\IntegrationDefinitions;

class MailgunCheck extends ManagedKeyCheck
{
    public function provider(): string
    {
        return IntegrationDefinitions::MAILGUN;
    }

    protected function requiredKeys(): array
    {
        return ['api_key'];
    }

    public function run(array $overrides = []): CheckResult
    {
        $endpoint = trim($this->value('endpoint', $overrides) ?? 'api.eu.mailgun.net', '/');
        $domain = $this->value('domain', $overrides);
        $request = $this->http()->withBasicAuth('api', (string) $this->value('api_key', $overrides));

        if (blank($domain)) {
            $response = $request->get('https://'.$endpoint.'/v4/domains', ['limit' => 1]);

            return match (true) {
                $response->successful() => CheckResult::warn('API key accepted, but no sending domain is set.'),
                in_array($response->status(), [401, 403], true) => CheckResult::fail('Mailgun rejected the API key (check the key and the EU/US endpoint).'),
                default => CheckResult::fail('Mailgun API returned HTTP '.$response->status().'.'),
            };
        }

        $response = $request->get('https://'.$endpoint.'/v4/domains/'.rawurlencode($domain));

        if (in_array($response->status(), [401, 403], true)) {
            return CheckResult::fail('Mailgun rejected the API key (check the key and the EU/US endpoint).');
        }

        if ($response->status() === 404) {
            return CheckResult::fail("Sending domain {$domain} was not found in this Mailgun account.");
        }

        if (! $response->successful()) {
            return CheckResult::fail('Mailgun API returned HTTP '.$response->status().'.');
        }

        $state = (string) $response->json('domain.state', 'unknown');

        return $state === 'active'
            ? CheckResult::pass("API key accepted. Sending domain {$domain} is active.")
            : CheckResult::warn("API key accepted, but sending domain {$domain} is {$state} in Mailgun.");
    }
}
