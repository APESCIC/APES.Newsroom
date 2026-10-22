<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserLoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_detail_page_lists_recent_sign_in_events_with_result_method_ip_and_device(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->log($user, null, 'auth.failed', ['reason' => 'invalid_credentials'], '198.51.100.1');
        $this->log($user, $user, 'auth.login', ['guard' => 'web', 'remember' => false], '198.51.100.2');
        $this->log($user, $user, 'auth.two_factor_passed', ['method' => 'recovery_code'], '198.51.100.2');
        $this->log($user, $user, 'auth.passkey_login', ['passkey_id' => 3], '198.51.100.3');
        $this->log($user, $user, 'auth.logout', [], '198.51.100.3');
        $this->log($user, $user, 'account.password_changed', [], '198.51.100.3');
        $this->log($other, $other, 'auth.login', [], '203.0.113.50');

        $this->actingAs($admin)->get("/admin/users/{$user->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('user.login_history', 5)
                ->where('user.login_history.0.action', 'auth.logout')
                ->where('user.login_history.0.result', 'info')
                ->where('user.login_history.1.action', 'auth.passkey_login')
                ->where('user.login_history.1.result', 'success')
                ->where('user.login_history.1.method', 'passkey')
                ->where('user.login_history.2.method', 'recovery_code')
                ->where('user.login_history.3.result', 'success')
                ->where('user.login_history.3.ip_address', '198.51.100.2')
                ->where('user.login_history.3.browser', 'Firefox')
                ->where('user.login_history.3.platform', 'Windows')
                ->where('user.login_history.4.action', 'auth.failed')
                ->where('user.login_history.4.result', 'failure')
                ->where('user.login_history', fn ($rows) => collect($rows)->pluck('ip_address')->doesntContain('203.0.113.50')));
    }

    public function test_history_is_limited_to_the_most_recent_events(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (range(1, 30) as $i) {
            $this->log($user, $user, 'auth.login', [], "198.51.100.{$i}");
        }

        $this->actingAs($admin)->get("/admin/users/{$user->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('user.login_history', 25)
                ->where('user.login_history.0.ip_address', '198.51.100.30'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(User $subject, ?User $actor, string $action, array $payload, string $ip): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'payload' => $payload,
            'ip_address' => $ip,
            'user_agent' => self::FIREFOX,
        ]);
    }
}
