<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\Contracts\IntegrationCheck;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Support\Facades\Http;

/**
 * Cloudron SSO credentials stay in the environment, so this only confirms
 * the discovery document is reachable and well formed. No key is sent.
 */
class CloudronOidcCheck implements IntegrationCheck
{
    public function provider(): string
    {
        return IntegrationDefinitions::CLOUDRON_OIDC;
    }

    public function configured(array $overrides = []): bool
    {
        return filled(config('services.cloudron_oidc.discovery_url'));
    }

    public function run(array $overrides = []): CheckResult
    {
        $response = Http::timeout(ManagedKeyCheck::TIMEOUT_SECONDS)
            ->connectTimeout(ManagedKeyCheck::CONNECT_TIMEOUT_SECONDS)
            ->acceptJson()
            ->get((string) config('services.cloudron_oidc.discovery_url'));

        if (! $response->successful()) {
            return CheckResult::fail('Discovery document returned HTTP '.$response->status().'.');
        }

        if (blank($response->json('issuer')) || blank($response->json('authorization_endpoint')) || blank($response->json('token_endpoint'))) {
            return CheckResult::fail('Discovery document is missing the issuer or endpoints.');
        }

        if (blank(config('services.cloudron_oidc.client_id')) || blank(config('services.cloudron_oidc.client_secret'))) {
            return CheckResult::warn('Discovery document is reachable, but the OIDC client id or secret is not set.');
        }

        return CheckResult::pass('Discovery document is reachable.');
    }
}
