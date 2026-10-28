<?php

namespace Tests\Feature\Dev;

use App\Enums\Role;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_login_routes_are_unavailable_when_not_local(): void
    {
        $this->assertFalse(app()->environment('local'));

        $this->post('/_dev/login/public')->assertNotFound();
        $this->post('/_dev/logout')->assertNotFound();
    }

    public function test_dev_login_controller_aborts_outside_local_even_if_routed(): void
    {
        $this->assertFalse(app()->environment('local'));

        Route::middleware('web')->group(base_path('routes/dev.php'));

        $this->post('/_dev/login/public')->assertNotFound();
        $this->post('/_dev/logout')->assertNotFound();
    }

    public function test_each_role_can_authenticate_and_reach_expected_surfaces(): void
    {
        $this->enableLocalDevRoutes();
        $this->seed(DemoUsersSeeder::class);

        $cases = [
            'public' => [
                'role' => Role::Public,
                'redirect' => '/',
                'allowed' => ['/'],
                'forbidden' => ['/staff', '/staff/posts', '/admin', '/admin/moderation'],
            ],
            'staff' => [
                'role' => Role::Staff,
                'redirect' => '/staff',
                'allowed' => ['/staff', '/staff/posts'],
                'forbidden' => ['/admin', '/admin/moderation'],
            ],
            'admin' => [
                'role' => Role::Admin,
                'redirect' => '/admin',
                'allowed' => ['/staff', '/staff/posts', '/admin', '/admin/moderation'],
                'forbidden' => [],
            ],
            'super_admin' => [
                'role' => Role::SuperAdmin,
                'redirect' => '/admin',
                'allowed' => ['/staff', '/staff/posts', '/admin', '/admin/moderation'],
                'forbidden' => [],
            ],
        ];

        foreach ($cases as $roleKey => $case) {
            Auth::logout();

            $this->post("/_dev/login/{$roleKey}")
                ->assertRedirect($case['redirect']);

            $this->assertAuthenticated();
            $this->assertSame($case['role'], Auth::user()->role);

            foreach ($case['allowed'] as $path) {
                $this->get($path)->assertOk();
            }

            foreach ($case['forbidden'] as $path) {
                $this->get($path)->assertForbidden();
            }
        }
    }

    public function test_guest_logout_clears_the_session(): void
    {
        $this->enableLocalDevRoutes();
        $this->seed(DemoUsersSeeder::class);

        $this->post('/_dev/login/staff')->assertRedirect('/staff');
        $this->assertAuthenticated();

        $this->post('/_dev/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_unknown_role_returns_not_found(): void
    {
        $this->enableLocalDevRoutes();

        $this->post('/_dev/login/not-a-role')->assertNotFound();
    }

    public function test_inertia_shares_dev_tools_only_in_local(): void
    {
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('devTools', false));

        $this->app['env'] = 'local';

        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('devTools', true));
    }

    private function enableLocalDevRoutes(): void
    {
        // Switching env to local disables Laravel's unit-test CSRF bypass
        // (runningUnitTests checks APP_ENV === testing).
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);

        Route::middleware('web')->group(base_path('routes/dev.php'));
    }
}
