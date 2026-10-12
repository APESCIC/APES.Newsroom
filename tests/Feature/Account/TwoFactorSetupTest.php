<?php

namespace Tests\Feature\Account;

use App\Http\Controllers\Account\TwoFactorController;
use App\Models\User;
use App\Notifications\Security\TwoFactorEnabledNotification;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorSetupTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_starting_setup_requires_recent_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('account.security'))
            ->post(route('account.two-factor.store'))
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_confirming_setup_requires_recent_authentication(): void
    {
        $user = User::factory()->create();
        app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)
            ->post(route('account.two-factor.confirm'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_security_page_shows_the_qr_code_and_key_while_setup_is_pending(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.store'))
            ->assertRedirect(route('account.security'));

        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotNull($secret);

        $this->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Security')
                ->where('two_factor.enabled', false)
                ->where('two_factor.setup.secret', $secret)
                ->where('two_factor.setup.qr_image', fn (string $src) => str_starts_with($src, 'data:image/svg+xml;base64,')
                    && str_contains((string) base64_decode(substr($src, 26)), '<svg'))
            );
    }

    public function test_the_setup_key_is_withheld_once_recent_authentication_lapses(): void
    {
        $user = User::factory()->create();
        app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('two_factor.enabled', false)
                ->where('two_factor.setup', null)
            );
    }

    public function test_a_valid_code_turns_two_factor_on_and_shows_recovery_codes_once(): void
    {
        $user = User::factory()->create();
        app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.confirm'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'two-factor-enabled')
            ->assertSessionHas(TwoFactorController::RECOVERY_CODES_FLASH, fn (array $codes) => count($codes) === TwoFactorService::RECOVERY_CODE_COUNT);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.two_factor_enabled', 'actor_id' => $user->id]);
        Notification::assertSentTo($user, TwoFactorEnabledNotification::class);

        $this->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('two_factor.enabled', true)
                ->where('two_factor.recovery_codes_remaining', TwoFactorService::RECOVERY_CODE_COUNT)
                ->has('recovery_codes', TwoFactorService::RECOVERY_CODE_COUNT)
            );

        $this->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page->where('recovery_codes', null));
    }

    public function test_a_wrong_code_leaves_two_factor_off(): void
    {
        $user = User::factory()->create();
        app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->from(route('account.security'))
            ->post(route('account.two-factor.confirm'), ['code' => '000000'])
            ->assertRedirect(route('account.security'))
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        Notification::assertNothingSent();
    }

    public function test_starting_setup_again_does_not_turn_off_enabled_two_factor(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $secret = $user->fresh()->two_factor_secret;

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.store'))
            ->assertSessionHasErrors('two_factor');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
    }

    public function test_confirm_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();
        app(TwoFactorService::class)->beginSetup($user);
        $this->actingAs($user)->withSession($this->recentlyConfirmed());

        for ($i = 0; $i < 5; $i++) {
            $this->from(route('account.security'))->post(route('account.two-factor.confirm'), ['code' => '000000']);
        }

        $this->from(route('account.security'))
            ->post(route('account.two-factor.confirm'), ['code' => $this->totpFor($user)])
            ->assertSessionHasErrors();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_sso_accounts_cannot_set_up_app_two_factor(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.store'))
            ->assertForbidden();

        $this->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page->where('two_factor', null));
    }
}
