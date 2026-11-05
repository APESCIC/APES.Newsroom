<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\UserRoleChanger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_removes_a_stale_elevated_role_from_a_password_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['role' => Role::Staff]);
        ApiToken::issue($user, 'old-ci');
        $epoch = (int) $user->auth_epoch;

        $this->actingAs($admin)->withRecentAuthentication()
            ->from("/admin/users/{$user->id}")
            ->patch("/admin/users/{$user->id}/role", ['role' => 'public', 'reason' => 'Left the newsroom'])
            ->assertRedirect("/admin/users/{$user->id}")
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame(Role::Public, $user->role);
        $this->assertSame(0, $user->apiTokens()->count());
        $this->assertSame($epoch + 1, (int) $user->auth_epoch);

        $log = AuditLog::query()->where('action', 'user.role_changed')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['from' => 'staff', 'to' => 'public', 'reason' => 'Left the newsroom'], $log->payload);
    }

    public function test_admin_demotes_another_password_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $peer = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->withRecentAuthentication()
            ->patch("/admin/users/{$peer->id}/role", ['role' => 'public', 'reason' => 'Stale local admin'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Public, $peer->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_changed', 'actor_id' => $admin->id, 'subject_id' => $peer->id]);
    }

    public function test_promotion_is_refused_and_points_to_the_directory(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (['staff', 'admin'] as $role) {
            $this->actingAs($admin)->withRecentAuthentication()
                ->patch("/admin/users/{$user->id}/role", ['role' => $role, 'reason' => 'Promote'])
                ->assertSessionHasErrors(['role' => 'Staff and admin roles come from Cloudron directory groups. Add the person to the right group in Cloudron instead.']);
        }

        $this->assertSame(Role::Public, $user->fresh()->role);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.role_changed']);
    }

    public function test_role_changes_need_a_recent_sign_in_and_never_apply_to_sso_accounts_or_yourself(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $stale = User::factory()->create(['role' => Role::Staff]);
        $sso = User::factory()->staff()->create();

        $this->actingAs($admin)
            ->patch("/admin/users/{$stale->id}/role", ['role' => 'public', 'reason' => 'No re-auth'])
            ->assertRedirect(route('account.confirm-identity'));

        $this->actingAs($staff)->withRecentAuthentication()
            ->patch("/admin/users/{$stale->id}/role", ['role' => 'public', 'reason' => 'Staff try'])
            ->assertForbidden();

        $this->actingAs($admin)->withRecentAuthentication()
            ->patch("/admin/users/{$sso->id}/role", ['role' => 'public', 'reason' => 'Directory owns this'])
            ->assertForbidden();

        $this->actingAs($admin)->withRecentAuthentication()
            ->patch("/admin/users/{$admin->id}/role", ['role' => 'public', 'reason' => 'Myself'])
            ->assertForbidden();

        $this->assertSame(Role::Staff, $stale->fresh()->role);
        $this->assertSame(Role::Staff, $sso->fresh()->role);
    }

    public function test_detail_page_reports_who_may_change_the_role(): void
    {
        $admin = User::factory()->admin()->create();
        $stale = User::factory()->create(['role' => Role::Staff]);
        $sso = User::factory()->staff()->create();

        $this->actingAs($admin)->get("/admin/users/{$stale->id}")->assertInertia(fn ($page) => $page->where('user.can.change_role', true));
        $this->actingAs($admin)->get("/admin/users/{$sso->id}")->assertInertia(fn ($page) => $page->where('user.can.change_role', false));
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $only = User::factory()->create(['role' => Role::Admin]);
        $actor = User::factory()->staff()->create();

        $this->expectException(ValidationException::class);

        try {
            app(UserRoleChanger::class)->change($only, Role::Public, $actor, 'Test', Request::create('/'));
        } finally {
            $this->assertSame(Role::Admin, $only->fresh()->role);
        }
    }

    public function test_an_admin_can_be_demoted_when_another_remains(): void
    {
        User::factory()->admin()->create();
        $passwordAdmin = User::factory()->create(['role' => Role::Admin]);

        app(UserRoleChanger::class)->change($passwordAdmin, Role::Public, User::factory()->staff()->create(), 'Test', Request::create('/'));

        $this->assertSame(Role::Public, $passwordAdmin->fresh()->role);
    }
}
