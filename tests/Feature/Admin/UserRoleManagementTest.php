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

    public function test_super_admin_removes_a_stale_elevated_role_from_a_password_account(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->create(['role' => Role::Staff]);
        ApiToken::issue($user, 'old-ci');
        $epoch = (int) $user->auth_epoch;

        $this->actingAs($superAdmin)->withRecentAuthentication()
            ->from("/admin/users/{$user->id}")
            ->patch("/admin/users/{$user->id}/role", ['role' => 'public', 'reason' => 'Left the newsroom'])
            ->assertRedirect("/admin/users/{$user->id}")
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame(Role::Public, $user->role);
        $this->assertSame(0, $user->apiTokens()->count());
        $this->assertSame($epoch + 1, (int) $user->auth_epoch);

        $log = AuditLog::query()->where('action', 'user.role_changed')->sole();
        $this->assertSame($superAdmin->id, $log->actor_id);
        $this->assertSame(['from' => 'staff', 'to' => 'public', 'reason' => 'Left the newsroom'], $log->payload);
    }

    public function test_promotion_is_refused_and_points_to_the_directory(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();

        foreach (['staff', 'admin', 'super_admin'] as $role) {
            $this->actingAs($superAdmin)->withRecentAuthentication()
                ->patch("/admin/users/{$user->id}/role", ['role' => $role, 'reason' => 'Promote'])
                ->assertSessionHasErrors(['role' => 'Staff and admin roles come from Cloudron directory groups. Add the person to the right group in Cloudron instead.']);
        }

        $this->assertSame(Role::Public, $user->fresh()->role);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.role_changed']);
    }

    public function test_only_super_admins_can_change_roles_and_never_for_sso_accounts_or_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $stale = User::factory()->create(['role' => Role::Staff]);
        $sso = User::factory()->staff()->create();

        $this->actingAs($superAdmin)
            ->patch("/admin/users/{$stale->id}/role", ['role' => 'public', 'reason' => 'No re-auth'])
            ->assertRedirect(route('account.confirm-identity'));

        $this->actingAs($admin)->withRecentAuthentication()
            ->patch("/admin/users/{$stale->id}/role", ['role' => 'public', 'reason' => 'Admin try'])
            ->assertForbidden();

        $this->actingAs($superAdmin)->withRecentAuthentication()
            ->patch("/admin/users/{$sso->id}/role", ['role' => 'public', 'reason' => 'Directory owns this'])
            ->assertForbidden();

        $this->actingAs($superAdmin)->withRecentAuthentication()
            ->patch("/admin/users/{$superAdmin->id}/role", ['role' => 'public', 'reason' => 'Myself'])
            ->assertForbidden();

        $this->assertSame(Role::Staff, $stale->fresh()->role);
        $this->assertSame(Role::Staff, $sso->fresh()->role);
    }

    public function test_detail_page_reports_who_may_change_the_role(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $stale = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($admin)->get("/admin/users/{$stale->id}")->assertInertia(fn ($page) => $page->where('user.can.change_role', false));
        $this->actingAs($superAdmin)->get("/admin/users/{$stale->id}")->assertInertia(fn ($page) => $page->where('user.can.change_role', true));
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        $only = User::factory()->create(['role' => Role::SuperAdmin]);
        $actor = User::factory()->admin()->create();

        $this->expectException(ValidationException::class);

        try {
            app(UserRoleChanger::class)->change($only, Role::Public, $actor, 'Test', Request::create('/'));
        } finally {
            $this->assertSame(Role::SuperAdmin, $only->fresh()->role);
        }
    }

    public function test_a_super_admin_can_be_demoted_when_another_remains(): void
    {
        User::factory()->superAdmin()->create();
        $passwordSuperAdmin = User::factory()->create(['role' => Role::SuperAdmin]);

        app(UserRoleChanger::class)->change($passwordSuperAdmin, Role::Public, User::factory()->admin()->create(), 'Test', Request::create('/'));

        $this->assertSame(Role::Public, $passwordSuperAdmin->fresh()->role);
    }
}
