<?php

namespace Tests\Feature;

use App\Enums\IntegrationCheckStatus;
use App\Models\IntegrationCheckResult;
use App\Models\User;
use App\Services\Health\HealthCheckRunner;
use App\Services\Integrations\IntegrationSecrets;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationHealthChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mailgun.secret' => null,
            'services.mailgun.domain' => null,
            'services.mailgun.endpoint' => 'api.eu.mailgun.net',
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
            'newsroom.unsplash.access_key' => null,
            'newsroom.ai_assist.api_key' => null,
            'services.slack.notifications.bot_user_oauth_token' => null,
            'services.slack.notifications.channel' => null,
            'services.cloudron_oidc.discovery_url' => null,
        ]);
    }

    public function test_mailgun_active_domain_passes_and_result_is_stored(): void
    {
        $this->store(IntegrationDefinitions::MAILGUN, 'api_key', 'key-mailgun-abcdef');
        $this->store(IntegrationDefinitions::MAILGUN, 'domain', 'mg.apesnews.org.uk');
        Http::fake(['api.eu.mailgun.net/v4/domains/mg.apesnews.org.uk' => Http::response(['domain' => ['state' => 'active']])]);
        $admin = User::factory()->admin()->create();

        $result = $this->runner()->run(IntegrationDefinitions::MAILGUN, IntegrationCheckResult::TRIGGER_MANUAL, $admin);

        $this->assertSame(IntegrationCheckStatus::Pass, $result->status);
        $this->assertSame($admin->id, $result->user_id);
        $this->assertNotNull($result->checked_at);
        $this->assertGreaterThanOrEqual(0, $result->duration_ms);
        $this->assertDatabaseHas('integration_check_results', ['provider' => 'mailgun', 'status' => 'pass', 'trigger' => 'manual']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public function test_mailgun_rejected_key_fails_without_echoing_the_key(): void
    {
        $this->store(IntegrationDefinitions::MAILGUN, 'api_key', 'key-mailgun-wrong1');
        $this->store(IntegrationDefinitions::MAILGUN, 'domain', 'mg.apesnews.org.uk');
        Http::fake(['api.eu.mailgun.net/*' => Http::response(['message' => 'Invalid key key-mailgun-wrong1'], 401)]);

        $result = $this->runner()->run(IntegrationDefinitions::MAILGUN, IntegrationCheckResult::TRIGGER_MANUAL);

        $this->assertSame(IntegrationCheckStatus::Fail, $result->status);
        $this->assertStringNotContainsString('key-mailgun-wrong1', $result->message);
    }

    public function test_overrides_are_tested_instead_of_the_stored_value(): void
    {
        $this->store(IntegrationDefinitions::UNSPLASH, 'access_key', 'stored-unsplash-key');
        Http::fake(['api.unsplash.com/*' => Http::response([])]);

        $this->runner()->run(IntegrationDefinitions::UNSPLASH, IntegrationCheckResult::TRIGGER_PRE_SAVE, null, ['access_key' => 'candidate-unsplash-key']);

        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Client-ID candidate-unsplash-key');
        Http::assertNotSent(fn (Request $request) => $request->header('Authorization')[0] === 'Client-ID stored-unsplash-key');
    }

    public function test_stripe_reports_mode_and_webhook_endpoint(): void
    {
        $this->store(IntegrationDefinitions::STRIPE, 'secret', 'sk_test_abc123');
        $this->store(IntegrationDefinitions::STRIPE, 'webhook_secret', 'whsec_abc123');
        Http::fake([
            'api.stripe.com/v1/account' => Http::response(['id' => 'acct_1']),
            'api.stripe.com/v1/webhook_endpoints*' => Http::response(['data' => [
                ['url' => 'https://www.apesnews.org.uk/stripe/webhook', 'status' => 'enabled'],
            ]]),
        ]);

        $result = $this->runner()->run(IntegrationDefinitions::STRIPE, IntegrationCheckResult::TRIGGER_MANUAL);

        $this->assertSame(IntegrationCheckStatus::Pass, $result->status);
        $this->assertStringContainsString('Test mode', $result->message);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_stripe_without_webhook_endpoint_warns(): void
    {
        $this->store(IntegrationDefinitions::STRIPE, 'secret', 'sk_live_abc123');
        Http::fake([
            'api.stripe.com/v1/account' => Http::response(['id' => 'acct_1']),
            'api.stripe.com/v1/webhook_endpoints*' => Http::response(['data' => []]),
        ]);

        $result = $this->runner()->run(IntegrationDefinitions::STRIPE, IntegrationCheckResult::TRIGGER_MANUAL);

        $this->assertSame(IntegrationCheckStatus::Warn, $result->status);
        $this->assertStringContainsString('Live mode', $result->message);
    }

    public function test_openai_and_slack_checks(): void
    {
        config(['newsroom.ai_assist.enabled' => true]);
        $this->store(IntegrationDefinitions::OPENAI, 'api_key', 'sk-openai-abc');
        $this->store(IntegrationDefinitions::SLACK, 'bot_token', 'xoxb-slack-abc');
        $this->store(IntegrationDefinitions::SLACK, 'default_channel', '#newsroom');
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => []]),
            'slack.com/api/auth.test' => Http::response(['ok' => false, 'error' => 'invalid_auth']),
        ]);

        $this->assertSame(IntegrationCheckStatus::Pass, $this->runner()->run(IntegrationDefinitions::OPENAI, IntegrationCheckResult::TRIGGER_MANUAL)->status);

        $slack = $this->runner()->run(IntegrationDefinitions::SLACK, IntegrationCheckResult::TRIGGER_MANUAL);
        $this->assertSame(IntegrationCheckStatus::Fail, $slack->status);
        $this->assertStringContainsString('invalid_auth', $slack->message);
    }

    public function test_cloudron_oidc_checks_the_discovery_document(): void
    {
        config([
            'services.cloudron_oidc.discovery_url' => 'https://my.cloudron.test/.well-known/openid-configuration',
            'services.cloudron_oidc.client_id' => 'client',
            'services.cloudron_oidc.client_secret' => 'secret',
        ]);
        Http::fake(['my.cloudron.test/*' => Http::response([
            'issuer' => 'https://my.cloudron.test',
            'authorization_endpoint' => 'https://my.cloudron.test/auth',
            'token_endpoint' => 'https://my.cloudron.test/token',
        ])]);

        $this->assertSame(IntegrationCheckStatus::Pass, $this->runner()->run(IntegrationDefinitions::CLOUDRON_OIDC, IntegrationCheckResult::TRIGGER_MANUAL)->status);
    }

    public function test_timeouts_fail_with_a_plain_message(): void
    {
        $this->store(IntegrationDefinitions::OPENAI, 'api_key', 'sk-openai-abc');
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.openai.com with Bearer sk-openai-abc'));

        $result = $this->runner()->run(IntegrationDefinitions::OPENAI, IntegrationCheckResult::TRIGGER_MANUAL);

        $this->assertSame(IntegrationCheckStatus::Fail, $result->status);
        $this->assertStringContainsString('Could not reach OpenAI', $result->message);
        $this->assertStringNotContainsString('sk-openai-abc', $result->message);
    }

    public function test_unconfigured_provider_fails_without_calling_out(): void
    {
        Http::fake();

        $result = $this->runner()->run(IntegrationDefinitions::MAILGUN, IntegrationCheckResult::TRIGGER_MANUAL);

        $this->assertSame(IntegrationCheckStatus::Fail, $result->status);
        $this->assertSame('Not configured.', $result->message);
        Http::assertNothingSent();
    }

    public function test_run_configured_skips_unconfigured_providers(): void
    {
        $this->store(IntegrationDefinitions::UNSPLASH, 'access_key', 'stored-unsplash-key');
        Http::fake(['api.unsplash.com/*' => Http::response([])]);

        $results = $this->runner()->runConfigured(IntegrationCheckResult::TRIGGER_SCHEDULE);

        $this->assertSame(['unsplash'], array_keys($results));
    }

    public function test_pre_save_results_do_not_count_as_the_latest_status(): void
    {
        $this->store(IntegrationDefinitions::UNSPLASH, 'access_key', 'stored-unsplash-key');
        Http::fake(['api.unsplash.com/*' => Http::sequence()->push([])->push([], 401)]);

        $this->runner()->run(IntegrationDefinitions::UNSPLASH, IntegrationCheckResult::TRIGGER_SCHEDULE);
        $this->runner()->run(IntegrationDefinitions::UNSPLASH, IntegrationCheckResult::TRIGGER_PRE_SAVE, null, ['access_key' => 'bad-candidate-key']);

        $this->assertSame(IntegrationCheckStatus::Pass, IntegrationCheckResult::latestFor('unsplash')?->status);
    }

    public function test_old_results_are_purged(): void
    {
        IntegrationCheckResult::query()->create([
            'provider' => 'stripe', 'status' => 'pass', 'message' => 'ok', 'duration_ms' => 5,
            'trigger' => 'schedule', 'checked_at' => now()->subDays(45),
        ]);
        IntegrationCheckResult::query()->create([
            'provider' => 'stripe', 'status' => 'pass', 'message' => 'ok', 'duration_ms' => 5,
            'trigger' => 'schedule', 'checked_at' => now()->subDays(2),
        ]);

        $this->artisan('newsroom:purge-security-data')->assertSuccessful();

        $this->assertSame(1, IntegrationCheckResult::query()->count());
    }

    private function store(string $provider, string $key, string $value): void
    {
        app(IntegrationSecrets::class)->set($provider, $key, $value, null);
    }

    private function runner(): HealthCheckRunner
    {
        return app(HealthCheckRunner::class);
    }
}
