<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\Auth\CloudronOidcProvider;
use App\Services\Auth\StaffOidcIdentity;
use App\Services\Auth\StaffReconciler;
use App\Services\Auth\StaffReconcileResult;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_successful_password_login_records_one_login_event(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->withHeader('User-Agent', 'AuditTest/1.0')
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('home'));

        $log = $this->singleLog('auth.login');
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('AuditTest/1.0', $log->user_agent);
        $this->assertSame(['guard' => 'web', 'remember' => false], $log->payload);
    }

    public function test_failed_login_for_known_account_records_reason_without_credentials(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-guess']);

        $log = $this->singleLog('auth.failed');
        $this->assertNull($log->actor_id);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertSame('invalid_credentials', $log->payload['reason']);
        $this->assertNoSecrets($log, ['wrong-password-guess']);
    }

    public function test_failed_login_for_unknown_email_stores_no_email(): void
    {
        $this->post('/login', ['email' => 'stranger@example.com', 'password' => 'whatever-guess']);

        $log = $this->singleLog('auth.failed');
        $this->assertNull($log->actor_id);
        $this->assertNull($log->subject_id);
        $this->assertSame('unknown_account', $log->payload['reason']);
        $this->assertNoSecrets($log, ['stranger@example.com', 'whatever-guess']);
    }

    public function test_lockout_is_recorded_once(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        foreach (range(1, 6) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-guess']);
        }

        $log = $this->singleLog('auth.lockout');
        $this->assertSame($user->id, $log->subject_id);
        $this->assertNoSecrets($log, ['wrong-password-guess']);
    }

    public function test_logout_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertSame($user->id, $this->singleLog('auth.logout')->actor_id);
    }

    public function test_registration_and_verification_are_recorded(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'New Reader',
            'email' => 'reader@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $user = User::query()->where('email', 'reader@example.com')->firstOrFail();
        $this->assertSame($user->id, $this->singleLog('auth.registered')->actor_id);

        $verificationUrl = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$verificationUrl) {
            $verificationUrl = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->get($verificationUrl);

        $log = $this->singleLog('auth.email_verified');
        $this->assertSame($user->id, $log->actor_id);
        $this->assertNoSecrets($log, [$verificationUrl]);
    }

    public function test_password_reset_is_recorded_without_the_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $log = $this->singleLog('auth.password_reset');
        $this->assertSame($user->id, $log->actor_id);
        $this->assertNoSecrets($log, [$token, self::PASSWORD]);
    }

    public function test_magic_link_request_and_use_are_recorded_without_the_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/login/magic-link', ['email' => $user->email]);

        $url = null;
        Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $notification) use (&$url) {
            $url = (fn () => $this->url)->call($notification);

            return true;
        });

        $requested = $this->singleLog('auth.magic_link_requested');
        $this->assertNull($requested->actor_id);
        $this->assertSame($user->id, $requested->subject_id);

        $this->get($url)->assertRedirect(route('home'));

        $used = $this->singleLog('auth.magic_link_used');
        $this->assertSame($user->id, $used->actor_id);
        $this->assertNoSecrets($used, [$url]);
        $this->assertNoSecrets($requested, [$url]);
    }

    public function test_magic_link_request_for_unknown_email_records_nothing(): void
    {
        $this->post('/login/magic-link', ['email' => 'nobody@example.com']);

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_oidc_sign_in_allowed_is_recorded(): void
    {
        $user = User::factory()->staff()->create(['external_id' => 'oidc-1', 'email' => 'staff@example.com']);
        $this->mockOidc(StaffReconcileResult::allow($user));

        $this->oidcCallback()->assertRedirect(route('home'));

        $log = $this->singleLog('auth.oidc_allowed');
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('staff', $log->payload['role']);
    }

    public function test_oidc_sign_in_denied_is_recorded_with_a_reason_category(): void
    {
        $this->mockOidc(StaffReconcileResult::deny('Staff sign-in failed: not a member.'));

        $this->oidcCallback()->assertRedirect(route('login'));

        $log = $this->singleLog('auth.oidc_denied');
        $this->assertNull($log->actor_id);
        $this->assertSame(['reason' => 'directory_denied'], $log->payload);
    }

    public function test_oidc_invalid_state_is_recorded(): void
    {
        $this->get('/auth/cloudron/callback?code=x&state=forged')->assertRedirect(route('login'));

        $this->assertSame(['reason' => 'invalid_state'], $this->singleLog('auth.oidc_denied')->payload);
    }

    public function test_sign_in_history_does_not_block_self_service_deletion(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->delete(route('account.destroy'))->assertRedirect(route('home'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $log = $this->singleLog('auth.login');
        $this->assertNull($log->actor_id);
        $this->assertSame(0, AuditLog::query()->where('action', 'auth.logout')->count());
    }

    public function test_privileged_audit_records_still_block_self_service_deletion(): void
    {
        $user = User::factory()->create();
        AuditLog::query()->create(['actor_id' => $user->id, 'action' => 'settings.updated']);

        $this->actingAs($user)
            ->from(route('account.show'))
            ->delete(route('account.destroy'))
            ->assertSessionHasErrors('delete_account');
    }

    private function singleLog(string $action): AuditLog
    {
        $logs = AuditLog::query()->where('action', $action)->get();
        $this->assertCount(1, $logs, "Expected exactly one {$action} audit record.");

        return $logs->first();
    }

    /**
     * @param  array<int, string|null>  $secrets
     */
    private function assertNoSecrets(AuditLog $log, array $secrets): void
    {
        $serialised = json_encode($log->getAttributes());

        foreach (array_filter($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, (string) $serialised);
        }
    }

    private function mockOidc(StaffReconcileResult $result): void
    {
        $provider = Mockery::mock(CloudronOidcProvider::class);
        $provider->shouldReceive('exchangeCodeForIdentity')
            ->andReturn(new StaffOidcIdentity(sub: 'oidc-1', email: 'staff@example.com', name: 'Staff User', emailVerified: true));
        $this->app->instance(CloudronOidcProvider::class, $provider);

        $reconciler = Mockery::mock(StaffReconciler::class);
        $reconciler->shouldReceive('reconcile')->andReturn($result);
        $this->app->instance(StaffReconciler::class, $reconciler);
    }

    private function oidcCallback(): TestResponse
    {
        return $this->withSession([
            'cloudron_oidc_state' => 'test-state',
            'cloudron_oidc_nonce' => 'test-nonce',
            'cloudron_oidc_pkce' => 'test-pkce',
        ])->get('/auth/cloudron/callback?code=valid-code&state=test-state');
    }
}
