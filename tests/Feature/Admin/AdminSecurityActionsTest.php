<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\Security\AdminAccountActionNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesPasskeys;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class AdminSecurityActionsTest extends TestCase
{
    use CreatesPasskeys, InteractsWithTwoFactor, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_force_sign_out_ends_every_session(): void
    {
        $user = User::factory()->create();
        $session = UserSession::factory()->create(['user_id' => $user->id]);
        $epoch = (int) $user->auth_epoch;

        $this->act($user, 'sign-out', 'Session hijack suspected')->assertSessionHas('status');

        $this->assertSame($epoch + 1, (int) $user->fresh()->auth_epoch);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertAudited('user.signed_out', $user, ['reason' => 'Session hijack suspected']);
        $this->assertNotified($user, AdminAccountActionNotification::SIGNED_OUT);
    }

    public function test_force_password_reset_clears_the_password_and_sends_a_link(): void
    {
        $user = User::factory()->create();
        $epoch = (int) $user->auth_epoch;

        $this->act($user, 'password-reset', 'Password found in breach dump')->assertSessionHas('status');

        $user->refresh();
        $this->assertNull($user->password);
        $this->assertSame($epoch + 1, (int) $user->auth_epoch);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertAudited('user.password_reset_forced', $user, ['reason' => 'Password found in breach dump']);
        $this->assertNotified($user, AdminAccountActionNotification::PASSWORD_RESET);

        $this->assertFalse(Auth::guard('web')->validate(['email' => $user->email, 'password' => 'password']));
    }

    public function test_force_password_reset_is_refused_for_passwordless_accounts(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->act($user, 'password-reset', 'Reset please')->assertSessionHasErrors('reason');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.password_reset_forced']);
    }

    public function test_reset_two_factor_turns_it_off(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->act($user, 'two-factor/reset', 'Lost phone, identity verified')->assertSessionHas('status');

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertAudited('user.two_factor_reset', $user, ['reason' => 'Lost phone, identity verified']);
        $this->assertNotified($user, AdminAccountActionNotification::TWO_FACTOR_RESET);

        $this->act(User::factory()->create(), 'two-factor/reset', 'Nothing to reset')->assertSessionHasErrors('reason');
    }

    public function test_remove_passkeys_deletes_every_passkey(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user, 'Phone', $this->fakeAuthenticator());
        $this->createPasskey($user, 'Laptop', $this->fakeAuthenticator());

        $this->act($user, 'passkeys/remove', 'Device stolen')->assertSessionHas('status', 'Removed 2 passkeys.');

        $this->assertSame(0, $user->passkeys()->count());
        $this->assertAudited('user.passkeys_removed', $user, ['reason' => 'Device stolen', 'count' => 2]);
        $this->assertNotified($user, AdminAccountActionNotification::PASSKEYS_REMOVED);
    }

    public function test_sso_accounts_only_allow_sign_out(): void
    {
        $staff = User::factory()->staff()->create();

        foreach (['password-reset', 'two-factor/reset', 'passkeys/remove'] as $action) {
            $this->act($staff, $action, 'Managed elsewhere')->assertSessionHasErrors('reason');
        }

        $this->act($staff, 'sign-out', 'Leaver')->assertSessionHas('status');
        $this->assertSame(['user.signed_out'], AuditLog::query()->where('action', 'like', 'user.%')->pluck('action')->all());
    }

    public function test_actions_need_a_reason_recent_authentication_and_rank(): void
    {
        $user = User::factory()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/sign-out", ['reason' => 'No re-auth'])
            ->assertRedirect(route('account.confirm-identity'));
        $this->act($user, 'sign-out', '')->assertSessionHasErrors('reason');
        $this->act($otherAdmin, 'sign-out', 'Peer admin')->assertForbidden();
        $this->act($this->admin, 'sign-out', 'Myself')->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.signed_out']);
    }

    private function act(User $user, string $action, string $reason)
    {
        return $this->actingAs($this->admin)->withRecentAuthentication()
            ->from("/admin/users/{$user->id}")
            ->post("/admin/users/{$user->id}/{$action}", ['reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertAudited(string $action, User $user, array $payload): void
    {
        $log = AuditLog::query()->where('action', $action)->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertSame($payload, $log->payload);
    }

    private function assertNotified(User $user, string $action): void
    {
        Notification::assertSentTo($user, AdminAccountActionNotification::class, fn (AdminAccountActionNotification $n) => $n->action === $action);
    }
}
