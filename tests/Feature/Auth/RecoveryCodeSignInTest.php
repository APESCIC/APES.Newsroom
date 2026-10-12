<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\PendingTwoFactorLogin;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class RecoveryCodeSignInTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_a_recovery_code_completes_sign_in_and_lands_on_account_security(): void
    {
        $user = User::factory()->create();
        $codes = $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]])
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'recovery-code-used');

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session(PendingTwoFactorLogin::SESSION_KEY));
        $this->assertSame(7, app(TwoFactorService::class)->remainingRecoveryCodes($user->fresh()));

        $used = AuditLog::query()->where('action', 'auth.recovery_code_used')->sole();
        $this->assertSame($user->id, $used->actor_id);
        $this->assertSame(7, $used->payload['remaining']);
        $this->assertStringNotContainsString($codes[0], json_encode($used->payload));

        $passed = AuditLog::query()->where('action', 'auth.two_factor_passed')->sole();
        $this->assertSame('recovery_code', $passed->payload['method']);
    }

    public function test_recovery_codes_are_accepted_without_the_dash_and_in_upper_case(): void
    {
        $user = User::factory()->create();
        $codes = $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['recovery_code' => strtoupper(str_replace('-', '', $codes[1]))])
            ->assertRedirect(route('account.security'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_recovery_code_only_works_once(): void
    {
        $user = User::factory()->create();
        $codes = $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]]);
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]])
            ->assertSessionHasErrors('recovery_code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_failed', 'actor_id' => $user->id]);
    }

    public function test_a_wrong_recovery_code_counts_towards_the_attempt_limit(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        for ($attempt = 1; $attempt < PendingTwoFactorLogin::MAX_ATTEMPTS; $attempt++) {
            $this->post(route('two-factor.challenge.store'), ['recovery_code' => 'aaaaa-aaaaa'])
                ->assertSessionHasErrors('recovery_code');
        }

        $this->post(route('two-factor.challenge.store'), ['recovery_code' => 'aaaaa-aaaaa'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertNull(session(PendingTwoFactorLogin::SESSION_KEY));
        $this->assertGuest();
    }

    public function test_the_challenge_needs_either_a_code_or_a_recovery_code(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), [])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }
}
