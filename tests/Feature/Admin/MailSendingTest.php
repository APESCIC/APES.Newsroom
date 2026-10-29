<?php

namespace Tests\Feature\Admin;

use App\Mail\TransportTestMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mail\MailTransports;
use App\Services\Mail\SmtpHandshake;
use App\Services\Mail\TransportTester;
use App\Services\Settings\SettingsRepository;
use App\Support\Integrations\IntegrationDefinitions;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailSendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function transports(): MailTransports
    {
        return app(MailTransports::class);
    }

    private function setCustomSmtp(string $host = 'smtp.eu.mailgun.org', string $scheme = 'smtp', string $port = '587'): void
    {
        $secrets = app(IntegrationSecrets::class);
        $secrets->set(IntegrationDefinitions::CUSTOM_SMTP, 'host', $host, null);
        $secrets->set(IntegrationDefinitions::CUSTOM_SMTP, 'port', $port, null);
        $secrets->set(IntegrationDefinitions::CUSTOM_SMTP, 'scheme', $scheme, null);
        $secrets->set(IntegrationDefinitions::CUSTOM_SMTP, 'username', 'postmaster@mail.apesnews.org.uk', null);
        $secrets->set(IntegrationDefinitions::CUSTOM_SMTP, 'password', 'smtp-secret-password-1234', null);
    }

    /**
     * @param  array<string, string|int>  $settings
     */
    private function save(User $user, array $settings): TestResponse
    {
        $nested = [];
        foreach ($settings as $key => $value) {
            data_set($nested, $key, $value);
        }

        return $this->actingAs($user)->withRecentAuthentication()->put('/admin/settings/mail-sending', ['settings' => $nested]);
    }

    public function test_unset_settings_keep_the_starting_setup(): void
    {
        $this->assertSame(MailTransports::CLOUDRON_SMTP, $this->transports()->systemTransport());
        $this->assertSame(MailTransports::MAILGUN_API, $this->transports()->newsletterTransport());
        $this->assertSame(MailTransports::NONE, $this->transports()->systemFallback());

        $this->assertSame(MailTransports::CLOUDRON_SMTP, $this->transports()->effectiveNewsletterTransport());
        config(['services.mailgun.secret' => 'key-1234']);
        $this->assertSame(MailTransports::MAILGUN_API, $this->transports()->effectiveNewsletterTransport());
        $this->assertSame('mailgun-api', $this->transports()->newsletterMailer());

        $this->transports()->applySystemMailer();
        $this->assertSame('array', config('mail.default'));
    }

    public function test_settings_switch_mailers_without_a_redeploy(): void
    {
        $this->setCustomSmtp(scheme: 'smtps', port: '465');
        $settings = app(SettingsRepository::class);
        $settings->set(SettingDefinitions::MAIL_SYSTEM_TRANSPORT, MailTransports::CUSTOM_SMTP);
        $settings->set(SettingDefinitions::MAIL_SYSTEM_FALLBACK, MailTransports::CLOUDRON_SMTP);

        $this->transports()->applySystemMailer(app('mail.manager'));

        $this->assertSame('system-failover', config('mail.default'));
        $this->assertSame(['custom-smtp', 'array'], config('mail.mailers.system-failover.mailers'));
        $this->assertSame('smtp.eu.mailgun.org', config('mail.mailers.custom-smtp.host'));
        $this->assertSame(465, config('mail.mailers.custom-smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.custom-smtp.scheme'));

        $settings->set(SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT, MailTransports::MAILGUN_API);
        $this->assertSame('mailgun-api', $this->transports()->newsletterMailer(), 'A chosen transport is used even before its key is set, so failures pause sends.');

        $settings->set(SettingDefinitions::MAIL_SYSTEM_FALLBACK, MailTransports::NONE);
        $this->transports()->applySystemMailer(app('mail.manager'));
        $this->assertSame('custom-smtp', config('mail.default'));
    }

    public function test_cloudron_smtp_keeps_its_own_from_for_newsletters(): void
    {
        config(['mail.cloudron_forces_from' => true, 'mail.from.address' => 'newsroom.app@apesnews.org.uk']);
        app(SettingsRepository::class)->set(SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT, MailTransports::CLOUDRON_SMTP);

        $identity = $this->transports()->newsletterIdentity(['address' => 'news@apespetcare.org.uk', 'name' => 'Pet Care', 'reply_to' => null]);

        $this->assertSame(['address' => 'newsroom.app@apesnews.org.uk', 'name' => 'Pet Care', 'reply_to' => 'news@apespetcare.org.uk'], $identity);
    }

    public function test_admins_can_view_but_only_super_admins_change(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/settings/mail-sending')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/MailSending/Index')
                ->where('canManage', false)
                ->has('settings', 5)
                ->has('transports', 3)
                ->has('features'));

        $this->save($admin, [SettingDefinitions::MAIL_SYSTEM_TRANSPORT => MailTransports::CLOUDRON_SMTP])->assertForbidden();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page->where('settings', fn ($settings) => collect($settings)->every(fn ($setting) => ! str_starts_with($setting['key'], 'mail.'))));
    }

    public function test_super_admin_changes_transports_with_validation_and_audit(): void
    {
        $owner = User::factory()->superAdmin()->create();

        $this->save($owner, [SettingDefinitions::MAIL_SYSTEM_TRANSPORT => MailTransports::MAILGUN_API])
            ->assertSessionHasErrors(['settings.'.SettingDefinitions::MAIL_SYSTEM_TRANSPORT => 'The Mailgun API key is not set under Integrations.']);

        $this->setCustomSmtp(host: 'smtp.zeptomail.eu');
        $this->save($owner, [SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT => MailTransports::CUSTOM_SMTP])
            ->assertSessionHasErrors('settings.'.SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT);
        $this->save($owner, [
            SettingDefinitions::MAIL_SYSTEM_TRANSPORT => MailTransports::CUSTOM_SMTP,
            SettingDefinitions::MAIL_SYSTEM_FALLBACK => MailTransports::CUSTOM_SMTP,
        ])->assertSessionHasErrors(['settings.'.SettingDefinitions::MAIL_SYSTEM_FALLBACK => 'The fallback must be a different transport from system mail.']);

        $this->save($owner, [
            SettingDefinitions::MAIL_SYSTEM_TRANSPORT => MailTransports::CUSTOM_SMTP,
            SettingDefinitions::MAIL_SYSTEM_FALLBACK => MailTransports::CLOUDRON_SMTP,
            SettingDefinitions::MAIL_SOFT_BOUNCE_LIMIT => 4,
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/settings/mail-sending');

        $this->assertSame(MailTransports::CUSTOM_SMTP, $this->transports()->systemTransport());
        $audit = AuditLog::query()->where('action', 'mail_sending.updated')->latest('id')->firstOrFail();
        $this->assertSame(['from' => 'cloudron_smtp', 'to' => 'custom_smtp'], $audit->payload['changes'][SettingDefinitions::MAIL_SYSTEM_TRANSPORT]);
        $this->assertSame(['from' => 3, 'to' => 4], $audit->payload['changes'][SettingDefinitions::MAIL_SOFT_BOUNCE_LIMIT]);
        $this->assertStringNotContainsString('smtp-secret-password', json_encode($audit->payload));
    }

    public function test_changes_need_recent_authentication(): void
    {
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->put('/admin/settings/mail-sending', ['settings' => ['mail' => ['system_transport' => 'cloudron_smtp']]])
            ->assertRedirect('/account/confirm-identity');
    }

    public function test_connection_check_signs_in_without_sending(): void
    {
        $this->setCustomSmtp();
        app(SettingsRepository::class)->set(SettingDefinitions::MAIL_SYSTEM_TRANSPORT, MailTransports::CUSTOM_SMTP);
        $seen = [];
        $this->app->instance(SmtpHandshake::class, new class($seen) extends SmtpHandshake
        {
            public function __construct(private array &$seen) {}

            public function check(array $config): ?string
            {
                $this->seen[] = $config;

                return $config['password'] === 'smtp-secret-password-1234' ? null : 'Expected response code "235" but got code "535".';
            }
        });
        Mail::fake();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->withRecentAuthentication()
            ->postJson('/admin/settings/mail-sending/test', ['purpose' => 'system', 'mode' => 'connection'])
            ->assertOk()
            ->assertJson(['ok' => true, 'transport' => 'custom_smtp', 'mode' => 'connection', 'recipients' => []]);

        $this->assertStringNotContainsString('smtp-secret-password', $response->getContent());
        $this->assertSame('smtp.eu.mailgun.org', $seen[0]['host']);
        Mail::assertNothingSent();
        $this->assertSame('passed', AuditLog::query()->where('action', 'mail_sending.tested')->firstOrFail()->payload['outcome']);
    }

    public function test_mailgun_connection_check_reports_a_refused_key(): void
    {
        config(['services.mailgun.secret' => 'bad-key-1234']);
        Http::fake(['api.eu.mailgun.net/v4/domains*' => Http::response(['message' => 'Forbidden'], 401)]);
        app(SettingsRepository::class)->set(SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT, MailTransports::MAILGUN_API);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson('/admin/settings/mail-sending/test', ['purpose' => 'newsletters', 'mode' => 'connection'])
            ->assertOk()
            ->assertJson(['ok' => false, 'error' => 'Mailgun refused the API key (HTTP 401).']);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.eu.mailgun.net/v4/domains'));
    }

    public function test_test_email_goes_through_the_chosen_transport_and_is_throttled(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create(['email' => 'admin@apes.org.uk']);

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson('/admin/settings/mail-sending/test', ['purpose' => 'system', 'mode' => 'send', 'extra_recipient' => 'Ops@Example.com'])
            ->assertOk()
            ->assertJson(['ok' => true, 'transport' => 'cloudron_smtp', 'recipients' => ['admin@apes.org.uk', 'ops@example.com']]);

        Mail::assertSent(TransportTestMail::class, fn (TransportTestMail $mail) => $mail->hasTo('admin@apes.org.uk') && $mail->hasBcc('ops@example.com'));
        $this->assertSame(['ok' => true], array_intersect_key(TransportTester::lastResult('system'), ['ok' => true]));

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($admin)->withRecentAuthentication()->postJson('/admin/settings/mail-sending/test', ['purpose' => 'system', 'mode' => 'connection'])->assertOk();
        }
        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson('/admin/settings/mail-sending/test', ['purpose' => 'system', 'mode' => 'connection'])
            ->assertStatus(429);
    }

    public function test_staff_cannot_view_or_test(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/admin/settings/mail-sending')->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->postJson('/admin/settings/mail-sending/test', ['purpose' => 'system', 'mode' => 'send'])->assertForbidden();
    }
}
