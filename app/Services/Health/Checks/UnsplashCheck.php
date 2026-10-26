<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Support\Integrations\IntegrationDefinitions;

class UnsplashCheck extends ManagedKeyCheck
{
    public function provider(): string
    {
        return IntegrationDefinitions::UNSPLASH;
    }

    protected function requiredKeys(): array
    {
        return ['access_key'];
    }

    public function run(array $overrides = []): CheckResult
    {
        $response = $this->http()
            ->withHeaders([
                'Authorization' => 'Client-ID '.$this->value('access_key', $overrides),
                'Accept-Version' => 'v1',
            ])
            ->get('https://api.unsplash.com/photos', ['per_page' => 1]);

        return match (true) {
            $response->successful() => CheckResult::pass('Access key accepted.'),
            $response->status() === 401 => CheckResult::fail('Unsplash rejected the access key.'),
            $response->status() === 403 => CheckResult::warn('Access key accepted, but the hourly rate limit is used up.'),
            default => CheckResult::fail('Unsplash API returned HTTP '.$response->status().'.'),
        };
    }
}
