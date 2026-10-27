<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserVerificationResendTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_resends_the_verification_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->resend($user, 'Member could not find the email')->assertSessionHas('status', 'Verification email sent.');

        Notification::assertSentTo($user, VerifyEmail::class);
        $log = AuditLog::query()->where('action', 'user.verification_resent')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['reason' => 'Member could not find the email'], $log->payload);
    }

    public function test_refused_for_verified_and_sso_accounts(): void
    {
        $this->resend(User::factory()->create(), 'Again')->assertSessionHasErrors('reason');
        $this->resend(User::factory()->staff()->create(['email_verified_at' => null]), 'SSO')->assertSessionHasErrors('reason');

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.verification_resent']);
    }

    public function test_resend_is_throttled_per_user(): void
    {
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->resend($user, 'Retry')->assertSessionHas('status');
        }

        $this->resend($user, 'Retry')->assertSessionHasErrors();
        $this->assertSame(10, AuditLog::query()->where('action', 'user.verification_resent')->count());
    }

    private function resend(User $user, string $reason)
    {
        return $this->actingAs($this->admin)->withRecentAuthentication()
            ->from(route('admin.users.show', $user))
            ->post(route('admin.users.verification', $user), ['reason' => $reason]);
    }
}
