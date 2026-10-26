<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSecret;
use App\Models\User;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Resolves integration keys: the encrypted database value first, then the
 * environment (config) value. Read at call time rather than through config()
 * so a key replaced in the admin panel reaches web requests and long-running
 * queue workers without a redeploy, even with config:cache.
 *
 * The shared cache only ever holds ciphertext; values are decrypted per call.
 */
class IntegrationSecrets
{
    public const CACHE_KEY = 'integration-secrets:all';

    public const SOURCE_DATABASE = 'database';

    public const SOURCE_ENVIRONMENT = 'environment';

    public function get(string $provider, string $key): ?string
    {
        $definition = IntegrationDefinitions::key($provider, $key);
        $stored = $this->storedValue($provider, $key);

        if ($stored !== null) {
            return $stored;
        }

        $fallback = config($definition['config']);

        return filled($fallback) ? (string) $fallback : null;
    }

    public function configured(string $provider, string $key): bool
    {
        return filled($this->get($provider, $key));
    }

    public function source(string $provider, string $key): ?string
    {
        if ($this->storedValue($provider, $key) !== null) {
            return self::SOURCE_DATABASE;
        }

        return filled(config(IntegrationDefinitions::key($provider, $key)['config'])) ? self::SOURCE_ENVIRONMENT : null;
    }

    /**
     * @return array{secret: IntegrationSecret, replaced: bool}
     */
    public function set(string $provider, string $key, string $value, ?User $actor): array
    {
        IntegrationDefinitions::key($provider, $key);

        $secret = IntegrationSecret::query()->firstOrNew(['provider' => $provider, 'key' => $key]);
        $replaced = $secret->exists;

        $secret->fill([
            'value' => $value,
            'last_four' => IntegrationSecret::lastFourOf($value),
            'set_by' => $actor?->id,
            'set_at' => now(),
            'rotated_at' => $replaced ? now() : null,
        ])->save();

        $this->flush();

        return ['secret' => $secret, 'replaced' => $replaced];
    }

    public function remove(string $provider, string $key): ?IntegrationSecret
    {
        IntegrationDefinitions::key($provider, $key);

        $secret = IntegrationSecret::query()->where(['provider' => $provider, 'key' => $key])->first();
        $secret?->delete();

        $this->flush();

        return $secret;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function storedValue(string $provider, string $key): ?string
    {
        $ciphertext = $this->ciphertexts()[$provider.'.'.$key] ?? null;

        if ($ciphertext === null) {
            return null;
        }

        try {
            $value = Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            Log::warning('Stored integration key could not be decrypted; using the environment value.', [
                'provider' => $provider,
                'key' => $key,
            ]);

            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function ciphertexts(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => IntegrationSecret::query()
            ->toBase()
            ->get(['provider', 'key', 'value'])
            ->mapWithKeys(fn (object $row) => [$row->provider.'.'.$row->key => (string) $row->value])
            ->all());
    }
}
