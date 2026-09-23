<?php

namespace App\Support;

final class AnalyticsConfig
{
    public const PROVIDERS = ['none', 'plausible', 'gtm-stub'];

    /**
     * @return array{provider: string, plausibleDomain: string|null, gtmContainerId: string|null, enabled: bool}
     */
    public static function publicPayload(): array
    {
        $provider = (string) config('newsroom.analytics.provider', 'none');
        if (! in_array($provider, self::PROVIDERS, true)) {
            $provider = 'none';
        }

        $plausibleDomain = filled(config('newsroom.analytics.plausible_domain'))
            ? (string) config('newsroom.analytics.plausible_domain')
            : null;
        $gtmId = filled(config('newsroom.analytics.gtm_container_id'))
            ? (string) config('newsroom.analytics.gtm_container_id')
            : null;

        $enabled = match ($provider) {
            'plausible' => $plausibleDomain !== null,
            'gtm-stub' => $gtmId !== null,
            default => false,
        };

        if (! $enabled) {
            $provider = 'none';
        }

        return [
            'provider' => $provider,
            'plausibleDomain' => $plausibleDomain,
            'gtmContainerId' => $gtmId,
            'enabled' => $enabled,
        ];
    }
}
