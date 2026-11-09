<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Tests\TestCase;

/**
 * docs/laragon/profile.json is the source of truth for this project's
 * Laragon settings; .env.example, vite.config.ts and the docs must agree.
 */
class LaragonProfileTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function profile(): array
    {
        return json_decode((string) file_get_contents(base_path('docs/laragon/profile.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_env_example_matches_the_laragon_profile(): void
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

    public function test_profile_doc_lists_the_same_values(): void
    {
        $profile = $this->profile();
        $doc = (string) file_get_contents(base_path('docs/laragon/profile.md'));

        foreach ([$profile['hostname'], $profile['database']['name'], (string) $profile['vite']['port'], 'PHP | '.$profile['php']['version'], 'Node.js | '.$profile['node']['version'], 'MySQL '.$profile['database']['version']] as $value) {
            $this->assertStringContainsString($value, $doc);
        }
    }

    public function test_composer_requires_the_profile_php_version(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('^'.$this->profile()['php']['version'], $composer['require']['php']);
        $this->assertArrayHasKey('demo:seed', $composer['scripts']);
        $this->assertArrayHasKey('demo:reset', $composer['scripts']);
        $this->assertArrayHasKey('setup', $composer['scripts']);
    }

    public function test_laragon_profile_template_covers_the_profile_services(): void
    {
        $ini = (string) file_get_contents(base_path('docs/laragon/apes-newsroom.ini'));

        foreach (['apache', 'mysql', 'php', 'nodejs'] as $service) {
            $this->assertStringContainsString("[{$service}]\nVersion={{{$service}}}", str_replace("\r\n", "\n", $ini));
        }
    }
}
