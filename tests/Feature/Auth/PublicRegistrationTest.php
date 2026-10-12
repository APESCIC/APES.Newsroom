<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_as_public(): void
    {
        $response = $this->post('/register', [
            'name' => 'Jamie Fox',
            'email' => 'jamie@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::where('email', 'jamie@example.com')->firstOrFail();
        $this->assertSame(Role::Public, $user->role);
        $this->assertSame('password', $user->auth_provider);
    }

    public function test_role_in_the_request_payload_is_ignored(): void
    {
        $this->post('/register', [
            'name' => 'Jamie Fox',
            'email' => 'jamie@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'role' => 'super_admin',
        ]);

        $user = User::where('email', 'jamie@example.com')->firstOrFail();
        $this->assertSame(Role::Public, $user->role);
    }
}
