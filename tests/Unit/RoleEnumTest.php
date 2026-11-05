<?php

namespace Tests\Unit;

use App\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleEnumTest extends TestCase
{
    public static function ordinalPairs(): array
    {
        $roles = [Role::Public, Role::Staff, Role::Admin];
        $cases = [];

        foreach ($roles as $subject) {
            foreach ($roles as $other) {
                $cases["{$subject->value} atLeast {$other->value}"] = [
                    $subject,
                    $other,
                    $subject->rank() >= $other->rank(),
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('ordinalPairs')]
    public function test_at_least_compares_ordinal_rank(Role $subject, Role $other, bool $expected): void
    {
        $this->assertSame($expected, $subject->atLeast($other));
    }

    public function test_ranks_are_strictly_increasing_in_privilege_order(): void
    {
        $this->assertSame(0, Role::Public->rank());
        $this->assertSame(1, Role::Staff->rank());
        $this->assertSame(2, Role::Admin->rank());
    }

    public function test_admin_is_the_highest_role(): void
    {
        $this->assertSame(['public', 'staff', 'admin'], array_map(fn (Role $role) => $role->value, Role::cases()));
        $this->assertNull(Role::tryFrom('super_admin'));
    }
}
