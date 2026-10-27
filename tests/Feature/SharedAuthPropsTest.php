<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedAuthPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_has_no_staff_or_admin_access_flags(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', null)
                ->where('auth.can.accessStaff', false)
                ->where('auth.can.accessAdmin', false)
                ->where('auth.can.reviewPosts', false));
    }

    public function test_public_user_has_no_staff_or_admin_access_flags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.role', 'public')
                ->where('auth.can.accessStaff', false)
                ->where('auth.can.accessAdmin', false));
    }

    public function test_staff_can_access_staff_but_not_admin(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.accessStaff', true)
                ->where('auth.can.accessAdmin', false)
                ->where('auth.can.reviewPosts', false));
    }

    public function test_admin_can_access_staff_and_admin(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.accessStaff', true)
                ->where('auth.can.accessAdmin', true)
                ->where('auth.can.accessSuperAdmin', false)
                ->where('auth.can.reviewPosts', true));
    }

    public function test_super_admin_can_access_staff_and_admin(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can.accessStaff', true)
                ->where('auth.can.accessAdmin', true)
                ->where('auth.can.accessSuperAdmin', true));
    }
}
