<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\LdapGroupLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReconcileStaffRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_demotes_staff_without_recognised_groups(): void
    {
        $user = User::factory()->admin()->create(['email' => 'staffer@example.com']);

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('staffer@example.com')->andReturn([]);
        $this->app->instance(LdapGroupLookup::class, $lookup);

        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $this->assertSame(Role::Public, $user->fresh()->role);
    }

    public function test_command_updates_role_when_ldap_membership_changes(): void
    {
        $user = User::factory()->staff()->create(['email' => 'staffer@example.com']);

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('staffer@example.com')->andReturn(['newsroom-admins']);
        $this->app->instance(LdapGroupLookup::class, $lookup);

        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $this->assertSame(Role::Admin, $user->fresh()->role);
    }

    public function test_command_ignores_the_retired_group_and_keeps_admin_from_newsroom_admin(): void
    {
        $user = User::factory()->admin()->create(['email' => 'owner@example.com']);

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('owner@example.com')->andReturn([
            'cn=newsroom.superadmin,ou=groups,dc=cloudron',
            'cn=newsroom.admin,ou=groups,dc=cloudron',
        ]);
        $this->app->instance(LdapGroupLookup::class, $lookup);

        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $this->assertSame(Role::Admin, $user->fresh()->role);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'staff.role_changed', 'subject_id' => $user->id]);
    }

    public function test_command_treats_membership_of_only_the_retired_group_as_no_group(): void
    {
        $user = User::factory()->admin()->create(['email' => 'stray@example.com']);

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('stray@example.com')->andReturn(['cn=newsroom.superadmin,ou=groups,dc=cloudron']);
        $this->app->instance(LdapGroupLookup::class, $lookup);

        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $this->assertSame(Role::Public, $user->fresh()->role);
    }
}
