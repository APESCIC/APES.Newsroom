<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_saves_security_settings_and_changes_are_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->put('/admin/settings', ['settings' => ['security' => [
                'password' => ['min_length' => 16, 'check_breached' => true],
                'session' => ['lifetime' => 30],
                'magic_links' => ['enabled' => false],
                'two_factor' => ['required_roles' => ['public']],
            ]]])
            ->assertRedirect('/admin/settings')
            ->assertSessionHasNoErrors();

        $settings = app(SettingsRepository::class);
        $this->assertSame(16, $settings->get(SettingDefinitions::PASSWORD_MIN_LENGTH));
        $this->assertTrue($settings->get(SettingDefinitions::PASSWORD_CHECK_BREACHED));
        $this->assertSame(30, $settings->get(SettingDefinitions::SESSION_LIFETIME));
        $this->assertFalse($settings->get(SettingDefinitions::MAGIC_LINKS_ENABLED));
        $this->assertSame(['public'], $settings->get(SettingDefinitions::TWO_FACTOR_REQUIRED_ROLES));

        $changes = AuditLog::query()->where('action', 'settings.updated')->sole()->payload['changes'];
        $this->assertSame(['from' => 12, 'to' => 16], $changes[SettingDefinitions::PASSWORD_MIN_LENGTH]);
        $this->assertSame(['from' => true, 'to' => false], $changes[SettingDefinitions::MAGIC_LINKS_ENABLED]);
        $this->assertSame(['from' => [], 'to' => ['public']], $changes[SettingDefinitions::TWO_FACTOR_REQUIRED_ROLES]);
    }

    public function test_values_outside_safe_bounds_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->from('/admin/settings')
            ->put('/admin/settings', ['settings' => ['security' => [
                'password' => ['min_length' => 8, 'check_breached' => 'maybe'],
                'session' => ['lifetime' => 5],
                'two_factor' => ['required_roles' => ['owner']],
            ]]])
            ->assertSessionHasErrors([
                'settings.security.password.min_length',
                'settings.security.password.check_breached',
                'settings.security.session.lifetime',
                'settings.security.two_factor.required_roles.0',
            ]);

        $this->assertSame(12, app(SettingsRepository::class)->get(SettingDefinitions::PASSWORD_MIN_LENGTH));
    }

    public function test_minimum_password_length_takes_effect_without_a_deploy(): void
    {
        Notification::fake();

        $this->register('fourteen-chars')->assertSessionHasNoErrors();
        $this->post('/logout');

        app(SettingsRepository::class)->set(SettingDefinitions::PASSWORD_MIN_LENGTH, 16);

        $this->register('fourteen-chars', 'second@example.com')->assertSessionHasErrors('password');
        $this->register('sixteen-chars-ok', 'third@example.com')->assertSessionHasNoErrors();
    }

    public function test_breach_check_takes_effect_without_a_deploy(): void
    {
        Notification::fake();
        $password = 'correct horse battery staple';
        $suffix = strtoupper(substr(sha1($password), 5));
        Http::fake(['api.pwnedpasswords.com/*' => Http::response("{$suffix}:42\r\n")]);

        app(SettingsRepository::class)->set(SettingDefinitions::PASSWORD_CHECK_BREACHED, false);
        $this->register($password)->assertSessionHasNoErrors();
        $this->post('/logout');

        app(SettingsRepository::class)->set(SettingDefinitions::PASSWORD_CHECK_BREACHED, true);
        $this->register($password, 'second@example.com')->assertSessionHasErrors('password');
    }

    public function test_session_lifetime_takes_effect_without_a_deploy(): void
    {
        app(SettingsRepository::class)->set(SettingDefinitions::SESSION_LIFETIME, 30);

        $response = $this->get('/');

        $this->assertSame(30, config('session.lifetime'));
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), $cookie->getExpiresTime(), 5);
    }

    public function test_magic_links_can_be_switched_off_without_a_deploy(): void
    {
        $this->get('/login/magic-link')->assertOk();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('magicLinksEnabled', true));

        app(SettingsRepository::class)->set(SettingDefinitions::MAGIC_LINKS_ENABLED, false);

        $this->get('/login/magic-link')->assertNotFound();
        $this->post('/login/magic-link', ['email' => 'someone@example.com'])->assertNotFound();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('magicLinksEnabled', false));
    }

    private function register(string $password, string $email = 'first@example.com')
    {
        return $this->from('/register')->post('/register', [
            'name' => 'New Member',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }
}
