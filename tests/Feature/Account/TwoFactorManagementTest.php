<?php

namespace Tests\Feature\Account;

use App\Http\Controllers\Account\TwoFactorController;
use App\Models\User;
use App\Notifications\Security\RecoveryCodesRegeneratedNotification;
use App\Notifications\Security\TwoFactorDisabledNotification;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorManagementTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_regenerating_recovery_codes_requires_recent_authentication(): void
    {
        $user = User::factory()->create();
        $codes = $this->enableTwoFactor($user);

        $this->actingAs($user)
            ->post(route('account.two-factor.recovery-codes'))
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertTrue(app(TwoFactorService::class)->consumeRecoveryCode($user->fresh(), $codes[0]));
    }

    public function test_regenerating_recovery_codes_replaces_the_old_ones(): void
    {
        $user = User::factory()->create();
        $oldCodes = $this->enableTwoFactor($user);

        $response = $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.recovery-codes'))
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'recovery-codes-regenerated');

        $newCodes = $response->getSession()->get(TwoFactorController::RECOVERY_CODES_FLASH);
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $newCodes);

        $service = app(TwoFactorService::class);
        $this->assertFalse($service->consumeRecoveryCode($user->fresh(), $oldCodes[0]));
        $this->assertTrue($service->consumeRecoveryCode($user->fresh(), $newCodes[0]));

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.recovery_codes_regenerated', 'actor_id' => $user->id]);
        Notification::assertSentTo($user, RecoveryCodesRegeneratedNotification::class);
    }

    public function test_regenerating_recovery_codes_needs_two_factor_to_be_on(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.recovery-codes'))
            ->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_turning_off_two_factor_requires_recent_authentication(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->actingAs($user)
            ->delete(route('account.two-factor.destroy'))
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_turning_off_two_factor_clears_it_and_notifies_the_account_holder(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->delete(route('account.two-factor.destroy'))
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'two-factor-disabled');

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.two_factor_disabled', 'actor_id' => $user->id]);
        Notification::assertSentTo($user, TwoFactorDisabledNotification::class);
    }

    public function test_after_turning_off_two_factor_sign_in_skips_the_challenge(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->delete(route('account.two-factor.destroy'));
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_cancelling_a_pending_setup_is_silent(): void
    {
        $user = User::factory()->create();
        app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->delete(route('account.two-factor.destroy'))
            ->assertRedirect(route('account.security'))
            ->assertSessionMissing('status');

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'account.two_factor_disabled']);
        Notification::assertNothingSent();
    }
}
