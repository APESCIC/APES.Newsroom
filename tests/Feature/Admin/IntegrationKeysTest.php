<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IntegrationCheckResult;
use App\Models\IntegrationSecret;
use App\Models\User;
use App\Services\Auth\RecentAuthentication;
use App\Services\Integrations\IntegrationSecrets;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationKeysTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_supersecretvalue9876';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
            'services.mailgun.secret' => null,
            'services.mailgun.domain' => null,
            'newsroom.unsplash.access_key' => null,
        ]);
    }

    public function test_staff_cannot_view_or_change_integrations(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->withRecentAuthentication()->get(route('admin.settings.integrations.index'))->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET])
            ->assertForbidden();
        $this->actingAs($staff)->post(route('admin.settings.integrations.test', 'stripe'))->assertForbidden();

        $this->assertSame(0, IntegrationSecret::query()->count());
    }

    public function test_admin_can_view_and_test_but_not_change_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $this->stripeOk();
        app(IntegrationSecrets::class)->set('stripe', 'secret', self::SECRET, null);

        $this->actingAs($admin)->get(route('admin.settings.integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Integrations/Index')
                ->where('canManage', false));

        $this->actingAs($admin)->post(route('admin.settings.integrations.test', 'stripe'))
            ->assertRedirect(route('admin.settings.integrations.index'));
        $this->assertDatabaseHas('integration_check_results', ['provider' => 'stripe', 'trigger' => 'manual', 'user_id' => $admin->id]);

        $this->actingAs($admin)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => 'sk_test_otherkey1234'])
            ->assertForbidden();
        $this->actingAs($admin)->withRecentAuthentication()
            ->delete(route('admin.settings.integrations.destroy', ['stripe', 'secret']))
            ->assertForbidden();

        $this->assertSame(self::SECRET, app(IntegrationSecrets::class)->get('stripe', 'secret'));
    }

    public function test_super_admin_saves_a_key_after_a_passing_check(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->stripeOk();

        $this->actingAs($owner)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET])
            ->assertRedirect(route('admin.settings.integrations.index'))
            ->assertSessionHas('status', 'Stripe secret key saved.');

        $this->assertSame(self::SECRET, app(IntegrationSecrets::class)->get('stripe', 'secret'));
        $this->assertDatabaseHas('integration_check_results', ['provider' => 'stripe', 'trigger' => IntegrationCheckResult::TRIGGER_PRE_SAVE]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'integration_key.set', 'actor_id' => $owner->id]);
    }

    public function test_failing_check_blocks_saving_unless_forced(): void
    {
        $owner = User::factory()->superAdmin()->create();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided: '.self::SECRET]], 401)]);

        $this->actingAs($owner)->withRecentAuthentication()
            ->from(route('admin.settings.integrations.index'))
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET])
            ->assertRedirect(route('admin.settings.integrations.index'))
            ->assertSessionHasErrors('secret_value')
            ->assertSessionHas('integration_failed_check', ['provider' => 'stripe', 'key' => 'secret']);

        $this->assertSame(0, IntegrationSecret::query()->count());
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode(session('errors')->getBag('default')->all()));

        $this->actingAs($owner)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET, 'force' => true])
            ->assertRedirect(route('admin.settings.integrations.index'));

        $this->assertSame(self::SECRET, app(IntegrationSecrets::class)->get('stripe', 'secret'));
        $log = AuditLog::query()->where('action', 'integration_key.set')->sole();
        $this->assertTrue($log->payload['forced']);
        $this->assertSame('fail', $log->payload['check_status']);
    }

    public function test_changes_need_a_recent_identity_confirmation(): void
    {
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET])
            ->assertRedirect(route('account.confirm-identity'));

        $this->actingAs($owner)
            ->putJson(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET])
            ->assertStatus(423);

        $this->actingAs($owner)
            ->withSession([RecentAuthentication::SESSION_KEY => now()->subMinutes(6)->getTimestamp()])
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET])
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertSame(0, IntegrationSecret::query()->count());
    }

    public function test_key_changes_are_rate_limited(): void
    {
        $owner = User::factory()->superAdmin()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($owner)->withRecentAuthentication()
                ->delete(route('admin.settings.integrations.destroy', ['stripe', 'secret']))
                ->assertRedirect();
        }

        $this->actingAs($owner)->withRecentAuthentication()
            ->deleteJson(route('admin.settings.integrations.destroy', ['stripe', 'secret']))
            ->assertStatus(429);
    }

    public function test_invalid_values_and_unknown_keys_are_rejected(): void
    {
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => 'pk_test_wrongkind'])
            ->assertSessionHasErrors('secret_value');

        $this->actingAs($owner)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'nope']), ['secret_value' => 'x'])
            ->assertNotFound();

        $this->assertSame(0, IntegrationSecret::query()->count());
    }

    public function test_values_never_reach_the_page_audit_log_or_flashed_input(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->stripeOk();

        $this->actingAs($owner)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => self::SECRET]);
        $this->actingAs($owner)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'secret']), ['secret_value' => 'sk_test_secondsecret5432']);
        $this->actingAs($owner)->withRecentAuthentication()
            ->put(route('admin.settings.integrations.update', ['stripe', 'webhook_secret']), ['secret_value' => 'too-short-and-invalid'])
            ->assertSessionHasErrors('secret_value');
        $this->assertArrayNotHasKey('secret_value', session()->getOldInput());

        $response = $this->actingAs($owner)->get(route('admin.settings.integrations.index'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canManage', true)
            ->where('providers.1.provider', 'stripe')
            ->where('providers.1.keys.0.source', 'database')
            ->where('providers.1.keys.0.last_four', '5432')
            ->where('providers.1.keys.0.value', null)
            ->where('providers.1.rotations.0.action', 'replaced')
            ->where('providers.1.rotations.1.action', 'set'));

        $this->assertStringNotContainsString('sk_test_secondsecret5432', $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        $audit = (string) DB::table('audit_logs')->pluck('payload')->implode(' ');
        $this->assertStringNotContainsString(self::SECRET, $audit);
        $this->assertStringNotContainsString('sk_test_secondsecret5432', $audit);
        $this->assertSame(1, AuditLog::query()->where('action', 'integration_key.replaced')->count());
    }

    public function test_non_sensitive_values_are_shown_in_full(): void
    {
        $owner = User::factory()->superAdmin()->create();
        app(IntegrationSecrets::class)->set(IntegrationDefinitions::MAILGUN, 'domain', 'mg.apesnews.org.uk', null);

        $this->actingAs($owner)->get(route('admin.settings.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('providers.0.provider', 'mailgun')
                ->where('providers.0.keys.1.key', 'domain')
                ->where('providers.0.keys.1.value', 'mg.apesnews.org.uk'));
    }

    public function test_removing_a_key_falls_back_to_the_environment(): void
    {
        config(['newsroom.unsplash.access_key' => 'env-unsplash-key-1234']);
        $owner = User::factory()->superAdmin()->create();
        app(IntegrationSecrets::class)->set('unsplash', 'access_key', 'db-unsplash-key-9999', $owner);

        $this->actingAs($owner)->withRecentAuthentication()
            ->delete(route('admin.settings.integrations.destroy', ['unsplash', 'access_key']))
            ->assertRedirect(route('admin.settings.integrations.index'));

        $this->assertSame('env-unsplash-key-1234', app(IntegrationSecrets::class)->get('unsplash', 'access_key'));
        $log = AuditLog::query()->where('action', 'integration_key.removed')->sole();
        $this->assertSame(['provider' => 'unsplash', 'key' => 'access_key', 'last_four' => '9999'], $log->payload);
    }

    private function stripeOk(): void
    {
        Http::fake([
            'api.stripe.com/v1/account' => Http::response(['id' => 'acct_1']),
            'api.stripe.com/v1/webhook_endpoints*' => Http::response(['data' => []]),
        ]);
    }
}
