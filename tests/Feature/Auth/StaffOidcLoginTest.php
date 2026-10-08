<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Exceptions\Auth\LdapUnreachableException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\LdapGroupLookup;
use App\Services\Auth\StaffOidcIdentity;
use App\Services\Auth\StaffReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StaffOidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_GROUP = 'newsroom-staff';

    private const ADMIN_GROUP = 'newsroom-admins';

    private const SUPER_ADMIN_GROUP = 'newsroom-super-admins';

    private function identity(string $sub = 'oidc-sub-1', bool $emailVerified = true): StaffOidcIdentity
    {
        return new StaffOidcIdentity(sub: $sub, email: 'staffer@example.com', name: 'Staffer Person', emailVerified: $emailVerified);
    }

    private function reconcilerWithGroups(array $groups): StaffReconciler
    {
        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->andReturn($groups);

        return $this->app->make(StaffReconciler::class, ['ldap' => $lookup]);
    }

    private function reconcilerThatFails(): StaffReconciler
    {
        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->andThrow(new LdapUnreachableException('down'));

        return $this->app->make(StaffReconciler::class, ['ldap' => $lookup]);
    }

    public function test_a_single_recognised_group_grants_staff(): void
    {
        $result = $this->reconcilerWithGroups([self::STAFF_GROUP])->reconcile($this->identity());

        $this->assertTrue($result->allowed);
        $this->assertSame(Role::Staff, $result->user->role);
        $this->assertSame('cloudron_oidc', $result->user->auth_provider);
        $this->assertNull($result->user->password);
    }

    public function test_an_admin_group_grants_admin(): void
    {
        $result = $this->reconcilerWithGroups([self::ADMIN_GROUP])->reconcile($this->identity());

        $this->assertTrue($result->allowed);
        $this->assertSame(Role::Admin, $result->user->role);
    }

    public function test_a_super_admin_group_grants_super_admin(): void
    {
        $result = $this->reconcilerWithGroups([self::SUPER_ADMIN_GROUP])->reconcile($this->identity());

        $this->assertTrue($result->allowed);
        $this->assertSame(Role::SuperAdmin, $result->user->role);
    }

    public function test_membership_in_multiple_groups_grants_the_highest_matched_role(): void
    {
        $result = $this->reconcilerWithGroups([self::STAFF_GROUP, self::ADMIN_GROUP])->reconcile($this->identity());

        $this->assertTrue($result->allowed);
        $this->assertSame(Role::Admin, $result->user->role);
    }

    public function test_login_is_denied_when_ldap_is_unreachable(): void
    {
        $result = $this->reconcilerThatFails()->reconcile($this->identity());

        $this->assertFalse($result->allowed);
        $this->assertNull($result->user);
        $this->assertNotNull($result->denialReason);
        $this->assertSame(0, User::count());
    }

    public function test_login_is_denied_when_ldap_is_unreachable_and_does_not_touch_an_existing_users_role(): void
    {
        $existing = User::factory()->admin()->create(['external_id' => 'oidc-sub-1']);

        $this->reconcilerThatFails()->reconcile($this->identity());

        $existing->refresh();
        $this->assertSame(Role::Admin, $existing->role);
    }

    public function test_login_is_denied_when_the_user_has_no_recognised_group(): void
    {
        $result = $this->reconcilerWithGroups(['cn=some-unrelated-group,dc=apes,dc=org,dc=uk'])->reconcile($this->identity());

        $this->assertFalse($result->allowed);
        $this->assertSame(0, User::count());
    }

    public function test_login_is_denied_when_the_user_belongs_to_no_groups_at_all(): void
    {
        $result = $this->reconcilerWithGroups([])->reconcile($this->identity());

        $this->assertFalse($result->allowed);
    }

    public function test_reauthenticating_into_only_a_lower_group_downgrades_the_stored_role(): void
    {
        $existing = User::factory()->admin()->create(['external_id' => 'oidc-sub-1']);
        $this->assertSame(Role::Admin, $existing->role);

        $result = $this->reconcilerWithGroups([self::STAFF_GROUP])->reconcile($this->identity('oidc-sub-1'));

        $this->assertTrue($result->allowed);
        $this->assertSame($existing->id, $result->user->id);
        $this->assertSame(Role::Staff, $result->user->fresh()->role);
    }

    public function test_a_staff_account_never_has_a_local_password_after_reconciliation(): void
    {
        $result = $this->reconcilerWithGroups([self::STAFF_GROUP])->reconcile($this->identity());

        $this->assertNull($result->user->password);
    }

    public function test_cloudron_memberof_dn_matches_dotted_group_cn(): void
    {
        $result = $this->reconcilerWithGroups([
            'cn=newsroom.superadmin,ou=groups,dc=cloudron',
        ])->reconcile($this->identity());

        $this->assertTrue($result->allowed);
        $this->assertSame(Role::SuperAdmin, $result->user->role);
    }

    public function test_cloudron_admin_memberof_dn_grants_admin(): void
    {
        $result = $this->reconcilerWithGroups([
            'cn=newsroom.admin,ou=groups,dc=cloudron',
        ])->reconcile($this->identity());

        $this->assertTrue($result->allowed);
        $this->assertSame(Role::Admin, $result->user->role);
    }

    public function test_existing_cloudron_user_is_relinked_when_oidc_sub_changes(): void
    {
        $existing = User::factory()->staff()->create([
            'email' => 'staffer@example.com',
            'external_id' => 'old-sub',
            'auth_provider' => 'cloudron_oidc',
        ]);

        $result = $this->reconcilerWithGroups([self::STAFF_GROUP])
            ->reconcile($this->identity('new-sub'));

        $this->assertTrue($result->allowed);
        $this->assertSame($existing->id, $result->user->id);
        $this->assertSame('new-sub', $result->user->fresh()->external_id);
        $this->assertSame(1, User::count());
    }

    public function test_existing_password_user_is_linked_instead_of_duplicate_insert(): void
    {
        $existing = User::factory()->create([
            'email' => 'staffer@example.com',
            'auth_provider' => 'password',
            'external_id' => null,
            'role' => Role::Public,
        ]);

        $result = $this->reconcilerWithGroups([self::STAFF_GROUP])->reconcile($this->identity());

        $this->assertTrue($result->allowed);
        $this->assertSame($existing->id, $result->user->id);
        $this->assertSame(1, User::count());

        $existing->refresh();
        $this->assertSame('cloudron_oidc', $existing->auth_provider);
        $this->assertSame('oidc-sub-1', $existing->external_id);
        $this->assertSame(Role::Staff, $existing->role);
        $this->assertNull($existing->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.account_linked', 'subject_id' => $existing->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.role_changed', 'subject_id' => $existing->id]);
    }

    public function test_an_existing_account_is_not_linked_when_the_directory_email_is_unverified(): void
    {
        $existing = User::factory()->create([
            'email' => 'staffer@example.com',
            'auth_provider' => 'password',
            'external_id' => null,
        ]);

        $result = $this->reconcilerWithGroups([self::ADMIN_GROUP])
            ->reconcile($this->identity(emailVerified: false));

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('administrator', (string) $result->denialReason);
        $existing->refresh();
        $this->assertSame(Role::Public, $existing->role);
        $this->assertSame('password', $existing->auth_provider);
        $this->assertNull($existing->external_id);
    }

    public function test_an_existing_account_with_an_unverified_local_email_is_not_linked(): void
    {
        $existing = User::factory()->unverified()->create([
            'email' => 'staffer@example.com',
            'auth_provider' => 'password',
            'external_id' => null,
        ]);

        $result = $this->reconcilerWithGroups([self::ADMIN_GROUP])->reconcile($this->identity());

        $this->assertFalse($result->allowed);
        $this->assertSame(Role::Public, $existing->fresh()->role);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_a_sub_match_cannot_take_over_another_accounts_email(): void
    {
        User::factory()->staff()->create(['external_id' => 'oidc-sub-1', 'email' => 'old@example.com']);
        $other = User::factory()->create(['email' => 'staffer@example.com']);

        $result = $this->reconcilerWithGroups([self::STAFF_GROUP])->reconcile($this->identity());

        $this->assertFalse($result->allowed);
        $this->assertSame(Role::Public, $other->fresh()->role);
    }

    public function test_linking_and_promotion_revoke_existing_sessions(): void
    {
        $existing = User::factory()->create([
            'email' => 'staffer@example.com',
            'auth_provider' => 'password',
            'external_id' => null,
            'remember_token' => 'old-remember-token',
        ]);

        $this->reconcilerWithGroups([self::STAFF_GROUP])->reconcile($this->identity());

        $existing->refresh();
        $this->assertSame(1, $existing->auth_epoch);
        $this->assertNotSame('old-remember-token', $existing->remember_token);

        $this->reconcilerWithGroups([self::ADMIN_GROUP])->reconcile($this->identity());

        $this->assertSame(2, $existing->fresh()->auth_epoch);
    }

    public function test_an_unchanged_staff_login_keeps_existing_sessions(): void
    {
        $existing = User::factory()->staff()->create([
            'email' => 'staffer@example.com',
            'external_id' => 'oidc-sub-1',
        ]);

        $this->reconcilerWithGroups([self::STAFF_GROUP])->reconcile($this->identity());

        $this->assertSame(0, $existing->fresh()->auth_epoch);
    }
}
