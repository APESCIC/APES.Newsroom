<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.permissions'))
            ->assertRedirect('/login');
    }

    public function test_public_and_staff_cannot_open_the_matrix(): void
    {
        foreach ([Role::Public, Role::Staff] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get(route('admin.permissions'))
                ->assertForbidden();
        }
    }

    public function test_admin_can_open_the_matrix_with_role_descriptions(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.permissions'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Permissions/Index')
            ->has('roles', 3)
            ->has('abilities')
            ->where('roles.0.key', 'public')
            ->where('roles.0.label', 'Public')
            ->where('roles.1.key', 'staff')
            ->where('roles.2.key', 'admin')
            ->where('roles.0.description', PermissionMatrix::roles()[0]['description'])
            ->where('roles.1.description', PermissionMatrix::roles()[1]['description'])
            ->where('roles.2.description', PermissionMatrix::roles()[2]['description']));
    }

    public function test_matrix_lists_public_staff_and_admin_only(): void
    {
        $keys = array_column(PermissionMatrix::roles(), 'key');

        $this->assertSame(['public', 'staff', 'admin'], $keys);
        $this->assertNotContains('super_admin', $keys);
    }
}
