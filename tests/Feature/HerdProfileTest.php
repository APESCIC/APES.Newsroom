<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Tests\TestCase;

/**
 * docs/herd/profile.json is the source of truth for this project's Herd
 * settings; .env.example, vite.config.ts, composer.json and the docs must agree.
 */
class HerdProfileTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        return json_decode((string) file_get_contents(base_path('docs/herd/profile.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_env_example_matches_the_herd_profile(): void
    {
        $profile = $this->profile();
        $env = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertSame('local', $env['APP_ENV']);
        $this->assertSame($profile['appUrl'], $env['APP_URL']);
        $this->assertSame('http://'.$profile['hostname'], $env['APP_URL']);
        $this->assertSame($profile['database']['engine'], $env['DB_CONNECTION']);
        $this->assertSame($profile['database']['host'], $env['DB_HOST']);
        $this->assertSame((string) $profile['database']['port'], $env['DB_PORT']);
        $this->assertSame($profile['database']['name'], $env['DB_DATABASE']);
        $this->assertSame($profile['database']['username'], $env['DB_USERNAME']);
        $this->assertSame($profile['database']['password'], $env['DB_PASSWORD']);
        $this->assertStringEndsWith('_local', $env['DB_DATABASE']);
        $this->assertSame($profile['mail']['mailer'], $env['MAIL_MAILER']);
        $this->assertSame($profile['mail']['host'], $env['MAIL_HOST']);
        $this->assertSame((string) $profile['mail']['port'], $env['MAIL_PORT']);
        $this->assertSame($profile['redis']['client'], $env['REDIS_CLIENT']);
    }

    public function test_herd_site_is_named_after_the_hostname(): void
    {
        $profile = $this->profile();

        $this->assertSame($profile['herdSite'].'.test', $profile['hostname']);
        $this->assertSame($profile['project'], $profile['herdSite']);
        $this->assertSame('public', $profile['documentRoot']);
    }

    public function test_vite_uses_the_profile_host_and_port(): void
    {
        $profile = $this->profile();
        $vite = (string) file_get_contents(base_path('vite.config.ts'));

        $this->assertStringContainsString("host: '{$profile['vite']['host']}'", $vite);
        $this->assertStringContainsString("port: {$profile['vite']['port']},", $vite);
        $this->assertStringContainsString('strictPort: true', $vite);
        $this->assertNotSame(5173, $profile['vite']['port']);
    }

    public function test_site_is_pinned_to_the_composer_php_version(): void
    {
        $profile = $this->profile();
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($profile['php']['isolated']);
        $this->assertSame('cgi-fcgi', $profile['php']['sapi']);
        $this->assertSame('^'.$profile['php']['version'], $composer['require']['php']);
        $this->assertStringContainsString('herd isolate '.$profile['php']['version'], (string) file_get_contents(base_path('docs/herd/profile.md')));
    }

    public function test_composer_scripts_use_the_herd_setup(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains('powershell -NoProfile -ExecutionPolicy Bypass -File scripts/herd-setup.ps1', $composer['scripts']['setup']);
        $this->assertArrayHasKey('demo:seed', $composer['scripts']);
        $this->assertArrayHasKey('demo:reset', $composer['scripts']);
        $this->assertFileExists(base_path('scripts/herd-setup.ps1'));
        $this->assertFileExists(base_path('scripts/herd-env.ps1'));
    }

    public function test_profile_doc_lists_the_same_values(): void
    {
        $profile = $this->profile();
        $doc = (string) file_get_contents(base_path('docs/herd/profile.md'));

        foreach ([
            $profile['hostname'],
            $profile['database']['name'],
            (string) $profile['vite']['port'],
            'port '.$profile['mail']['port'],
            'PHP | '.$profile['php']['version'],
            'Node.js | '.$profile['node']['version'],
            'MySQL '.$profile['database']['version'],
        ] as $value) {
            $this->assertStringContainsString($value, $doc);
        }
    }

    public function test_public_htaccess_stays_safe_for_cloudron(): void
    {
        $depth = 0;
        $outside = [];
        foreach (file(base_path('public/.htaccess'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*<IfModule\s+(php_module|mod_php\d*\.c)\s*>/i', $line) === 1) {
                $depth++;
            } elseif ($depth > 0 && preg_match('#^\s*</IfModule>#i', $line) === 1) {
                $depth--;
            } elseif ($depth === 0 && preg_match('/^\s*php_(value|flag|admin_value|admin_flag)\b/i', $line) === 1) {
                $outside[] = trim($line);
            }
        }

        $this->assertSame([], $outside);
        $this->assertStringContainsString('HTTP_AUTHORIZATION', (string) file_get_contents(base_path('public/.htaccess')));
    }
}
