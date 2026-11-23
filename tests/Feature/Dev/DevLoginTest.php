<?php

namespace Tests\Feature\Dev;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\DemoAccounts;
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
        config(['newsroom.dev_login_enabled' => true]);

        $this->get('/_dev')->assertNotFound();
        $this->post('/_dev/login', ['profile' => 'public'])->assertNotFound();
        $this->post('/_dev/login/public')->assertNotFound();
        $this->post('/_dev/logout')->assertNotFound();
    }

    public function test_dev_login_is_not_found_when_the_flag_is_off(): void
    {
        $this->enableLocalEnv(flag: false);

        $this->get('/_dev')->assertNotFound();
        $this->post('/_dev/login', ['profile' => 'admin'])->assertNotFound();
        $this->post('/_dev/logout')->assertNotFound();
        $this->assertGuest();
    }

    public function test_dev_login_controller_aborts_outside_local_even_if_routed(): void
    {
        $this->assertFalse(app()->environment('local'));
        config(['newsroom.dev_login_enabled' => true]);

        Route::middleware('web')->group(base_path('routes/dev.php'));

        $this->get('/_dev')->assertNotFound();
        $this->post('/_dev/login', ['profile' => 'public'])->assertNotFound();
        $this->post('/_dev/logout')->assertNotFound();
    }

    public function test_index_is_ok_when_enabled_on_local(): void
    {
        $this->enableLocalDevRoutes();

        $this->get('/_dev')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dev/Index')
                ->has('profiles', 3));
    }

    public function test_get_login_role_redirects_to_index_instead_of_bare_404(): void
    {
        $this->enableLocalDevRoutes();

        $this->get('/_dev/login/admin')
            ->assertRedirect('/_dev');
    }

    public function test_missing_demo_users_are_created_on_login(): void
    {
        $this->enableLocalDevRoutes();
        $this->assertSame(0, User::query()->where('email', DemoAccounts::ADMIN)->count());

        $this->post('/_dev/login', ['profile' => 'admin'])->assertRedirect('/admin');

        $this->assertAuthenticated();
        $this->assertSame(1, User::query()->where('email', DemoAccounts::ADMIN)->count());
        $this->assertSame(Role::Admin, Auth::user()->role);
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
        ];

        foreach ($cases as $roleKey => $case) {
            Auth::logout();

            $this->post('/_dev/login', ['profile' => $roleKey])
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

    public function test_legacy_role_path_still_signs_in(): void
    {
        $this->enableLocalDevRoutes();

        $this->post('/_dev/login/staff')->assertRedirect('/staff');
        $this->assertAuthenticated();
        $this->assertSame(Role::Staff, Auth::user()->role);
    }

    public function test_guest_logout_clears_the_session(): void
    {
        $this->enableLocalDevRoutes();
        $this->seed(DemoUsersSeeder::class);

        $this->post('/_dev/login', ['profile' => 'staff'])->assertRedirect('/staff');
        $this->assertAuthenticated();

        $this->post('/_dev/logout')->assertRedirect(route('login').'#developer-signin');
        $this->assertGuest();
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->enableLocalDevRoutes();

        $this->post('/_dev/login', ['profile' => 'not-a-role'])->assertSessionHasErrors('profile');
        $this->assertGuest();
    }

    public function test_safe_return_path_is_honoured_and_off_site_paths_are_ignored(): void
    {
        $this->enableLocalDevRoutes();

        $this->post('/_dev/login', ['profile' => 'admin', 'return_to' => '/admin/moderation'])
            ->assertRedirect('/admin/moderation');

        Auth::logout();

        $this->post('/_dev/login', ['profile' => 'staff', 'return_to' => 'https://evil.example/admin'])
            ->assertRedirect('/staff');
    }

    public function test_fallback_after_switch_lands_on_role_home_with_notice(): void
    {
        $this->enableLocalDevRoutes();
        $this->seed(DemoUsersSeeder::class);

        $this->post('/_dev/login', ['profile' => 'admin', 'return_to' => '/admin']);
        $this->post('/_dev/login', ['profile' => 'staff', 'return_to' => '/admin/moderation'])
            ->assertRedirect('/admin/moderation');

        $this->assertTrue(Auth::user()->hasVerifiedEmail());

        $this->get('/admin/moderation')->assertRedirect('/staff');
        $this->followRedirects($this->get('/staff'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flash.status', 'Your new developer role cannot open that page, so you are here instead.'));
    }

    public function test_audits_a_role_switch(): void
    {
        $this->enableLocalDevRoutes();

        $this->post('/_dev/login', ['profile' => 'staff']);
        $this->post('/_dev/login', ['profile' => 'admin', 'return_to' => '/']);

        $entry = AuditLog::query()->where('action', 'auth.signed_in')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('dev_login', $entry->payload['method'] ?? null);
        $this->assertSame('admin', $entry->payload['role'] ?? null);
        $this->assertSame('staff', $entry->payload['switched_from'] ?? null);
    }

    public function test_inertia_shares_dev_tools_only_when_enabled_locally(): void
    {
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('devTools', false)
            ->where('devLogin', null));

        $this->enableLocalEnv(flag: false);
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('devTools', false));

        $this->enableLocalEnv(flag: true);
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('devTools', true)
            ->has('devLogin.profiles', 3));
    }

    public function test_login_page_shows_developer_panel_when_enabled(): void
    {
        $this->enableLocalEnv(flag: true);

        $this->get(route('login'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Login')
                ->where('devTools', true));
    }

    private function enableLocalDevRoutes(): void
    {
        $this->enableLocalEnv(flag: true);
        Route::middleware('web')->group(base_path('routes/dev.php'));
    }

    private function enableLocalEnv(bool $flag): void
    {
        // Switching env to local disables Laravel's unit-test CSRF bypass
        // (runningUnitTests checks APP_ENV === testing).
        $this->app['env'] = 'local';
        config(['newsroom.dev_login_enabled' => $flag]);
        $this->withoutMiddleware(PreventRequestForgery::class);
    }
}
