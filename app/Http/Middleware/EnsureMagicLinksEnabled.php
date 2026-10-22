<?php

namespace App\Http\Middleware;

use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMagicLinksEnabled
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $this->settings->get(SettingDefinitions::MAGIC_LINKS_ENABLED), 404);

        return $next($request);
    }
}
