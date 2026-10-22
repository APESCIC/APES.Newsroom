<?php

namespace App\Http\Middleware;

use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Applies the admin-configured idle session lifetime. Must run before
 * StartSession, which reads the lifetime when it opens the session.
 */
class ApplySessionLifetime
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            config(['session.lifetime' => (int) $this->settings->get(SettingDefinitions::SESSION_LIFETIME)]);
        } catch (Throwable) {
            // Keep the configured lifetime so /health can still report a database outage.
        }

        return $next($request);
    }
}
