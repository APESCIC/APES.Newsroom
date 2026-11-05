<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const ABILITIES = ['manageSecurity', 'lock', 'update', 'resendVerification', 'changeRole', 'erase'];

    /** A local (non-SSO) account, the only kind whose role can change in the app. */
    private const LOCAL = ['auth_provider' => null, 'external_id' => null];

    /**
     * Expected abilities of each actor role against a Public target.
     *
     * @return array<string, array{0: Role, 1: array<string, bool>}>
     */
    public static function abilitiesOnPublicTarget(): array
    {
        $none = ['viewAny' => false, 'view' => false, 'manageSecurity' => false, 'lock' => false, 'update' => false, 'resendVerification' => false, 'changeRole' => false, 'erase' => false];

        return [
            'public' => [Role::Public, $none],
            'staff' => [Role::Staff, [...$none, 'view' => true, 'update' => true]],
            'admin' => [Role::Admin, ['viewAny' => true, 'view' => true, 'manageSecurity' => true, 'lock' => true, 'update' => true, 'resendVerification' => true, 'changeRole' => true, 'erase' => true]],
        ];
    }

    /**
     * @param  array<string, bool>  $expected
     */
    #[DataProvider('abilitiesOnPublicTarget')]
    public function test_abilities_per_role(Role $role, array $expected): void
    {
        $actor = $this->userWithRole($role);
        $target = User::factory()->create();

        foreach ($expected as $ability => $allowed) {
            $result = $ability === 'viewAny' ? $actor->can($ability, User::class) : $actor->can($ability, $target);
            $this->assertSame($allowed, $result, "{$role->value} {$ability}");
        }
    }

    public function test_staff_only_view_and_edit_public_accounts(): void
    {
        $staff = User::factory()->staff()->create();
        $otherStaff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$otherStaff, $admin] as $target) {
            $this->assertFalse($staff->can('view', $target), "staff view {$target->role->value}");
            $this->assertFalse($staff->can('update', $target), "staff update {$target->role->value}");
        }
    }

    public function test_nobody_edits_or_erases_themselves(): void
    {
        foreach ([Role::Staff, Role::Admin] as $role) {
            $actor = $this->userWithRole($role);

            foreach (self::ABILITIES as $ability) {
                $this->assertFalse($actor->can($ability, $actor), "{$role->value} {$ability} on self");
            }
        }
    }

    public function test_admins_can_act_on_other_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $peer = User::factory()->admin()->create(self::LOCAL);
        $staff = User::factory()->staff()->create(self::LOCAL);

        foreach (self::ABILITIES as $ability) {
            $this->assertTrue($admin->can($ability, $peer), "admin {$ability} on admin");
            $this->assertTrue($admin->can($ability, $staff), "admin {$ability} on staff");
        }
    }

    public function test_staff_cannot_act_on_admins(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        foreach (self::ABILITIES as $ability) {
            $this->assertFalse($staff->can($ability, $admin), "staff {$ability} on admin");
        }
    }

    public function test_the_last_unlocked_admin_cannot_be_erased_locked_or_demoted(): void
    {
        $lockedAdmin = User::factory()->admin()->create(['locked_at' => now()]);
        $admin = User::factory()->admin()->create(self::LOCAL);

        foreach (['lock', 'erase', 'changeRole'] as $ability) {
            $this->assertFalse($lockedAdmin->can($ability, $admin), "{$ability} on the last unlocked admin");
        }

        $this->assertTrue($admin->can('erase', $lockedAdmin));
    }

    public function test_sso_accounts_keep_their_directory_role(): void
    {
        $admin = User::factory()->admin()->create();
        $ssoAdmin = User::factory()->admin()->create(['auth_provider' => 'cloudron_oidc']);

        $this->assertFalse($admin->can('changeRole', $ssoAdmin));
        $this->assertTrue($admin->can('lock', $ssoAdmin));
    }

    private function userWithRole(Role $role): User
    {
        $factory = User::factory();

        return match ($role) {
            Role::Public => $factory->create(),
            Role::Staff => $factory->staff()->create(),
            default => $factory->admin()->create(),
        };
    }
}
