<?php

namespace Tests\Feature;

use App\Jobs\RunIntegrationChecksJob;
use App\Models\IntegrationCheckResult;
use App\Models\User;
use App\Notifications\IntegrationCheckFailedNotification;
use App\Services\Health\HealthCheckRunner;
use App\Services\Health\IntegrationAlerts;
use App\Services\Integrations\IntegrationSecrets;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationChecksScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mailgun.secret' => null,
            'services.stripe.secret' => null,
            'newsroom.unsplash.access_key' => null,
            'newsroom.ai_assist.api_key' => null,
            'services.slack.notifications.bot_user_oauth_token' => null,
            'services.cloudron_oidc.discovery_url' => null,
        ]);
    }

    public function test_checks_are_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'integrations:check'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_command_queues_the_job(): void
    {
        Queue::fake();

        $this->artisan('integrations:check')->assertSuccessful();

        Queue::assertPushed(RunIntegrationChecksJob::class);
    }

    public function test_command_rejects_unknown_providers(): void
    {
        $this->artisan('integrations:check', ['--provider' => 'nope'])->assertFailed();
    }

    public function test_only_configured_providers_are_checked(): void
    {
        app(IntegrationSecrets::class)->set('unsplash', 'access_key', 'stored-unsplash-key', null);
        Http::fake(['api.unsplash.com/*' => Http::response([])]);

        $this->artisan('integrations:check', ['--now' => true])->assertSuccessful();

        $this->assertSame(['unsplash'], IntegrationCheckResult::query()->pluck('provider')->all());
        $this->assertSame('schedule', IntegrationCheckResult::query()->value('trigger'));
    }

    public function test_admins_are_alerted_once_when_a_check_starts_failing(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();
        $locked = User::factory()->admin()->create(['locked_at' => now()]);
        app(IntegrationSecrets::class)->set('unsplash', 'access_key', 'stored-unsplash-key', null);
        Http::fake(['api.unsplash.com/*' => Http::sequence()
            ->push([], 200)
            ->push([], 401)
            ->push([], 401)
            ->push([], 200)
            ->push([], 401)]);

        $run = fn () => (new RunIntegrationChecksJob)->handle(app(HealthCheckRunner::class), app(IntegrationAlerts::class));

        $run();
        Notification::assertNothingSent();

        $run();
        Notification::assertSentTo([$admin, $owner], IntegrationCheckFailedNotification::class);
        Notification::assertNotSentTo([$staff, $locked], IntegrationCheckFailedNotification::class);
        Notification::assertSentToTimes($admin, IntegrationCheckFailedNotification::class, 1);

        $run();
        Notification::assertSentToTimes($admin, IntegrationCheckFailedNotification::class, 1);

        $run();
        $run();
        Notification::assertSentToTimes($admin, IntegrationCheckFailedNotification::class, 2);
    }

    public function test_alert_email_names_the_provider_without_key_material(): void
    {
        $admin = User::factory()->admin()->create();
        $mail = (new IntegrationCheckFailedNotification('Unsplash', 'Unsplash rejected the access key.', '26 Oct 2026 13:00'))->toMail($admin);

        $this->assertStringContainsString('Unsplash', $mail->subject);
        $this->assertContains('Unsplash rejected the access key.', $mail->introLines);
        $this->assertSame(route('admin.settings.integrations.index'), $mail->actionUrl);
    }
}
