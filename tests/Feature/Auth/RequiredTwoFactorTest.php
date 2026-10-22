<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CreatesPasskeys;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class RequiredTwoFactorTest extends TestCase
{
    use CreatesPasskeys, InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_nothing_is_enforced_when_no_role_requires_two_factor(): void
    {
        $this->actingAs(User::factory()->create())->get('/account')->assertOk();
    }

    public function test_unenrolled_user_in_a_required_role_is_sent_to_security_setup(): void
    {
        $this->requireFor(['public']);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account')
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'two-factor-required');
        $this->actingAs($user)->post('/articles/any/comments', ['body' => 'Hi'])->assertRedirect(route('account.security'));
        $this->actingAs($user)->getJson('/account')->assertForbidden();

        $this->actingAs($user)->get(route('account.security'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Account/Security')->where('two_factor_required', true));
    }

    public function test_setup_and_sign_out_routes_stay_reachable(): void
    {
        $this->requireFor(['public']);
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.store'))
            ->assertRedirect();
        $this->assertNotNull($user->fresh()->two_factor_secret);

        $this->actingAs($user)->get(route('account.confirm-identity'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_user_with_an_authenticator_app_is_not_held_back(): void
    {
        $this->requireFor(['public']);
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($user)->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page->where('two_factor_required', false));
    }

    public function test_user_with_a_passkey_is_not_held_back(): void
    {
        $this->requireFor(['public']);
        $user = User::factory()->create();
        $this->createPasskey($user);

        $this->actingAs($user)->get('/account')->assertOk();
    }

    public function test_cloudron_sso_staff_are_exempt(): void
    {
        $this->requireFor(['public', 'staff', 'admin', 'super_admin']);

        $this->actingAs(User::factory()->staff()->create())->get('/staff/posts')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/settings')->assertOk();
    }

    public function test_users_in_other_roles_are_not_affected(): void
    {
        $this->requireFor(['admin']);

        $this->actingAs(User::factory()->create())->get('/account')->assertOk();
    }

    /**
     * @param  list<string>  $roles
     */
    private function requireFor(array $roles): void
    {
        app(SettingsRepository::class)->set(SettingDefinitions::TWO_FACTOR_REQUIRED_ROLES, $roles);
    }
}
