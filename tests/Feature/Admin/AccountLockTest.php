<?php

namespace Tests\Feature\Admin;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\MagicLinkNotification;
use App\Notifications\Security\AccountLockedNotification;
use App\Notifications\Security\AccountUnlockedNotification;
use App\Services\Auth\CloudronOidcProvider;
use App\Services\Auth\StaffOidcIdentity;
use App\Services\Auth\StaffReconciler;
use App\Services\Auth\StaffReconcileResult;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\CreatesPasskeys;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class AccountLockTest extends TestCase
{
    use CreatesPasskeys, InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    public function test_admin_locks_an_account_with_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $session = UserSession::factory()->create(['user_id' => $user->id]);
        $epoch = (int) $user->auth_epoch;

        $this->actingAs($admin)->withRecentAuthentication()
            ->from("/admin/users/{$user->id}")
            ->post("/admin/users/{$user->id}/lock", ['reason' => 'Credential stuffing reported'])
            ->assertRedirect("/admin/users/{$user->id}")
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue($user->isLocked());
        $this->assertSame('Credential stuffing reported', $user->locked_reason);
        $this->assertSame($admin->id, $user->locked_by);
        $this->assertSame($epoch + 1, (int) $user->auth_epoch);
        $this->assertNotNull($session->fresh()->revoked_at);

        $audit = AuditLog::query()->where('action', 'user.locked')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertSame(['reason' => 'Credential stuffing reported'], $audit->payload);
        Notification::assertSentTo($user, AccountLockedNotification::class);
    }

    public function test_admin_unlocks_an_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->lockedUser();

        $this->actingAs($admin)->withRecentAuthentication()
            ->post("/admin/users/{$user->id}/unlock", ['reason' => 'Owner verified by phone'])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->isLocked());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.unlocked', 'subject_id' => $user->id, 'actor_id' => $admin->id]);
        Notification::assertSentTo($user, AccountUnlockedNotification::class);
    }

    public function test_locking_needs_a_reason_and_recent_authentication(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/lock", ['reason' => 'x'.Str::random(5)])
            ->assertRedirect(route('account.confirm-identity'));

        $this->actingAs($admin)->withRecentAuthentication()
            ->post("/admin/users/{$user->id}/lock", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($user->fresh()->isLocked());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.locked']);
    }

    public function test_rank_rules_decide_who_can_be_locked(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $otherSuperAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();

        $attempts = [
            [User::factory()->create(), $staff, 403],
            [$staff, User::factory()->create(), 403],
            [$admin, $admin, 403],
            [$admin, $otherAdmin, 403],
            [$admin, $superAdmin, 403],
            [$superAdmin, $otherSuperAdmin, 403],
            [$admin, $staff, 302],
            [$superAdmin, $otherAdmin, 302],
        ];

        foreach ($attempts as [$actor, $target, $status]) {
            $this->actingAs($actor)->withRecentAuthentication()
                ->post("/admin/users/{$target->id}/lock", ['reason' => 'Rank check'])
                ->assertStatus($status);
        }

        $this->assertTrue($staff->fresh()->isLocked());
        $this->assertTrue($otherAdmin->fresh()->isLocked());
        $this->assertFalse($superAdmin->fresh()->isLocked());
        $this->assertFalse($otherSuperAdmin->fresh()->isLocked());
        $this->assertFalse($admin->fresh()->isLocked());
    }

    public function test_locked_account_cannot_sign_in_with_a_password(): void
    {
        $user = $this->lockedUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.locked_refused', 'subject_id' => $user->id]);
    }

    public function test_locked_account_cannot_request_or_use_a_magic_link(): void
    {
        $user = $this->lockedUser();

        $this->post(route('magic-link.store'), ['email' => $user->email])->assertOk();
        Notification::assertNotSentTo($user, MagicLinkNotification::class);
        $this->assertSame(0, $user->magicLinkTokens()->count());

        $token = Str::random(64);
        MagicLinkToken::query()->forceCreate(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10)]);

        $this->get(route('magic-link.consume', ['token' => $token]))->assertForbidden();
        $this->assertGuest();
    }

    public function test_locked_account_cannot_request_or_complete_a_password_reset(): void
    {
        $user = $this->lockedUser();

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
        Notification::assertNotSentTo($user, ResetPassword::class);

        $token = Password::createToken($user);
        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-long-password',
            'password_confirmation' => 'a-brand-new-long-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_locked_account_cannot_sign_in_with_a_passkey(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Phone', $authenticator);
        $user->forceFill(['locked_at' => now()])->save();

        $options = $this->postJson(route('passkey-login.options'))->getContent();
        $this->post(route('passkey-login.store'), ['credential' => $authenticator->assert($options)])
            ->assertSessionHasErrors('passkey');

        $this->assertGuest();
    }

    public function test_locking_during_a_two_factor_challenge_abandons_it(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $user->forceFill(['locked_at' => now()])->save();

        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)]);
        $this->assertGuest();
    }

    public function test_locked_staff_account_cannot_sign_in_through_cloudron_sso(): void
    {
        $user = User::factory()->staff()->create(['external_id' => 'oidc-locked', 'locked_at' => now()]);

        $provider = Mockery::mock(CloudronOidcProvider::class);
        $provider->shouldReceive('exchangeCodeForIdentity')->andReturn(new StaffOidcIdentity(sub: 'oidc-locked', email: $user->email, name: $user->name, emailVerified: true));
        $this->app->instance(CloudronOidcProvider::class, $provider);

        $reconciler = Mockery::mock(StaffReconciler::class);
        $reconciler->shouldReceive('reconcile')->andReturn(StaffReconcileResult::allow($user));
        $this->app->instance(StaffReconciler::class, $reconciler);

        $this->withSession(['cloudron_oidc_state' => 's', 'cloudron_oidc_nonce' => 'n', 'cloudron_oidc_pkce' => 'p'])
            ->get('/auth/cloudron/callback?code=c&state=s')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.locked_refused', 'subject_id' => $user->id]);
    }

    public function test_an_open_session_ends_on_the_next_request_after_locking(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account')->assertOk();

        $user->forceFill(['locked_at' => now()])->save();

        $this->get('/account')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_locked_staff_cannot_use_admin_api_tokens(): void
    {
        $staff = User::factory()->staff()->create();
        $token = ApiToken::issue($staff, 'ci')['token'];

        $this->getJson('/api/admin/v1/posts', ['Authorization' => "Bearer {$token}"])->assertOk();

        $staff->forceFill(['locked_at' => now()])->save();

        $this->getJson('/api/admin/v1/posts', ['Authorization' => "Bearer {$token}"])
            ->assertForbidden()
            ->assertJsonPath('message', 'Token owner account is locked.');
    }

    private function lockedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['locked_at' => now(), 'locked_reason' => 'Test lock'])->save();

        return $user;
    }
}
