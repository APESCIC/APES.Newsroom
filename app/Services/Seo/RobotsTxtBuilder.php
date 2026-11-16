<?php

namespace App\Services\Seo;

use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;

/**
 * Admin-managed robots.txt with a guaranteed Sitemap line (#672).
 */
class RobotsTxtBuilder
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function render(): string
    {
        $body = trim((string) $this->settings->get(SettingDefinitions::SEO_ROBOTS_TXT));
        if ($body === '') {
            $body = "User-agent: *\nDisallow:";
        }

        $sitemap = 'Sitemap: '.url('/sitemap.xml');
        if (! preg_match('/^\s*Sitemap:\s*/mi', $body)) {
            $body = rtrim($body)."\n\n".$sitemap;
        }

        return rtrim($body)."\n";
    }
}
