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

    /**
     * Expected abilities of each actor role against a Public target.
     *
     * @return array<string, array{0: Role, 1: array<string, bool>}>
     */
    public static function abilitiesOnPublicTarget(): array
    {
        $none = ['viewAny' => false, 'view' => false, 'manageSecurity' => false, 'update' => false, 'resendVerification' => false, 'changeRole' => false, 'erase' => false];

        return [
            'public' => [Role::Public, $none],
            'staff' => [Role::Staff, $none],
            'admin' => [Role::Admin, ['viewAny' => true, 'view' => true, 'manageSecurity' => true, 'update' => true, 'resendVerification' => true, 'changeRole' => false, 'erase' => false]],
            'super admin' => [Role::SuperAdmin, ['viewAny' => true, 'view' => true, 'manageSecurity' => true, 'update' => true, 'resendVerification' => true, 'changeRole' => true, 'erase' => true]],
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

    public function test_nobody_edits_or_erases_themselves(): void
    {
        foreach ([Role::Admin, Role::SuperAdmin] as $role) {
            $actor = $this->userWithRole($role);

            foreach (['manageSecurity', 'update', 'resendVerification', 'changeRole', 'erase'] as $ability) {
                $this->assertFalse($actor->can($ability, $actor), "{$role->value} {$ability} on self");
            }
        }
    }

    public function test_admins_cannot_act_on_peers_and_super_admins_cannot_erase_each_other(): void
    {
        $admin = User::factory()->admin()->create();
        $peer = User::factory()->admin()->create();
        $owner = User::factory()->superAdmin()->create();
        $otherOwner = User::factory()->superAdmin()->create();

        $this->assertFalse($admin->can('update', $peer));
        $this->assertFalse($admin->can('update', $owner));
        $this->assertTrue($owner->can('update', $peer));
        $this->assertTrue($owner->can('erase', $peer));
        $this->assertFalse($owner->can('update', $otherOwner));
        $this->assertFalse($owner->can('erase', $otherOwner));
    }

    private function userWithRole(Role $role): User
    {
        $factory = User::factory();

        return match ($role) {
            Role::Public => $factory->create(),
            Role::Staff => $factory->staff()->create(),
            Role::Admin => $factory->admin()->create(),
            Role::SuperAdmin => $factory->superAdmin()->create(),
        };
    }
}
