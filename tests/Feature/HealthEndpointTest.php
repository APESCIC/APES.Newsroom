<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_ok_when_dependencies_are_reachable(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
        $response->assertJsonStructure(['status', 'checks' => ['database', 'cache']]);
    }

    public function test_health_endpoint_hides_the_local_block_outside_local(): void
    {
        $this->get('/health')->assertOk()->assertJsonMissingPath('local');
    }

    public function test_health_endpoint_reports_php_and_revision_locally_for_the_preview_gate(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $response = $this->get('/health');

        $response->assertOk();
        $response->assertJsonPath('local.php', PHP_VERSION);
        $response->assertJsonPath('local.php_sapi', PHP_SAPI);
        $this->assertArrayHasKey('git_revision', $response->json('local'));
    }

    public function test_health_endpoint_response_contains_no_configuration_values(): void
    {
        $response = $this->get('/health');

        $body = $response->getContent();

        // The health check must never leak connection details. This is a
        // coarse guard, not exhaustive: it is here to catch an accidental
        // var_dump/exception-message leak, not to prove the absence of
        // every possible secret shape.
        $this->assertStringNotContainsString(config('database.connections.mysql.host') ?? '__unset__', $body);
        $this->assertStringNotContainsString((string) config('app.key'), $body);
    }
}
