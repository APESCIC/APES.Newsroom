<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Contracts\IntegrationCheck;
use App\Services\Integrations\IntegrationSecrets;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Base for checks of admin-managed providers: resolves keys from the
 * unsaved overrides first, then the managed store.
 */
abstract class ManagedKeyCheck implements IntegrationCheck
{
    public const TIMEOUT_SECONDS = 5;

    public const CONNECT_TIMEOUT_SECONDS = 3;

    public function __construct(protected readonly IntegrationSecrets $secrets) {}

    /**
     * @return list<string>
     */
    abstract protected function requiredKeys(): array;

    public function configured(array $overrides = []): bool
    {
        foreach ($this->requiredKeys() as $key) {
            if (blank($this->value($key, $overrides))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $overrides
     */
    protected function value(string $key, array $overrides): ?string
    {
        if (filled($overrides[$key] ?? null)) {
            return $overrides[$key];
        }

        return $this->secrets->get($this->provider(), $key);
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->acceptJson();
    }
}
