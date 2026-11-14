<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * The Vite dev server origin is added to the CSP only in the local
 * environment while public/hot exists (live preview under Herd).
 */
class ViteDevCspTest extends TestCase
{
    private string $hotFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotFile = storage_path('framework/testing/vite.hot');
        @mkdir(dirname($this->hotFile), 0777, true);
        file_put_contents($this->hotFile, 'http://127.0.0.1:5181');
        Vite::useHotFile($this->hotFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->hotFile);

        parent::tearDown();
    }

    private function csp(): string
    {
        return (string) $this->get('/health')->headers->get('Content-Security-Policy');
    }

    public function test_local_csp_allows_the_vite_dev_server(): void
    {
        $this->app['env'] = 'local';

        $csp = $this->csp();

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' http://127.0.0.1:5181;", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' http://127.0.0.1:5181;", $csp);
        $this->assertStringContainsString("connect-src 'self' http://127.0.0.1:5181 ws://127.0.0.1:5181;", $csp);
    }

    public function test_other_environments_never_allow_the_vite_dev_server(): void
    {
        foreach (['production', 'testing'] as $environment) {
            $this->app['env'] = $environment;

            $csp = $this->csp();

            $this->assertStringNotContainsString('5181', $csp, $environment);
            $this->assertStringContainsString("script-src 'self' 'unsafe-inline';", $csp);
            $this->assertStringContainsString("connect-src 'self';", $csp);
        }
    }
}
