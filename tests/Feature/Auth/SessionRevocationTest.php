<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\LdapGroupLookup;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_started_before_revocation_is_logged_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
        $this->nextRequest()->get('/account')->assertOk();

        app(UserSessionRevoker::class)->revoke($user->fresh());

        $this->nextRequest()->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_staff_session_created_before_a_role_change_is_rejected_afterwards(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staffer@example.com']);

        Auth::login($staff);
        $this->nextRequest()->get('/account')->assertOk();

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('staffer@example.com')->andReturn(['newsroom-admins']);
        $this->app->instance(LdapGroupLookup::class, $lookup);
        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $this->nextRequest()->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_new_login_after_revocation_works(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        app(UserSessionRevoker::class)->revoke($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('home'));
        $this->nextRequest()->get('/account')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Drop the in-memory guard user so the next request reloads it from
     * the session and database, as a real request would.
     */
    private function nextRequest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
