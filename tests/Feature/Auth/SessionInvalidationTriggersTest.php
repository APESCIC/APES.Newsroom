<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\LdapGroupLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Mockery;
use Tests\TestCase;

class SessionInvalidationTriggersTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_password_change_ends_other_sessions(): void
    {
        $user = User::factory()->create();
        $other = UserSession::factory()->for($user)->create();

        $this->actingAs($user)->get(route('account.show'));
        $this->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHas('status', 'password-updated');

        $this->assertEndedExceptCurrent($user, $other);
    }

    public function test_password_reset_ends_every_session(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $sessions = UserSession::factory()->for($user)->count(2)->create();
        $epoch = $user->auth_epoch;

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertSame($epoch + 1, $user->auth_epoch);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $sessions->each(fn (UserSession $session) => $this->assertNotNull($session->fresh()->revoked_at));
    }

    public function test_email_change_ends_other_sessions(): void
    {
        $user = User::factory()->create();
        $other = UserSession::factory()->for($user)->create();

        $this->actingAs($user)->withRecentAuthentication()->get(route('account.show'));
        $this->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'new-address@example.com',
        ])->assertSessionHas('status', 'profile-updated');

        $this->assertEndedExceptCurrent($user, $other);
    }

    public function test_a_name_only_update_keeps_other_sessions(): void
    {
        $user = User::factory()->create();
        $other = UserSession::factory()->for($user)->create();

        $this->actingAs($user)->patch(route('account.update'), [
            'name' => 'New Name',
            'email' => $user->email,
        ])->assertSessionHas('status', 'profile-updated');

        $this->assertNull($other->fresh()->revoked_at);
    }

    public function test_demotion_by_role_reconciliation_ends_every_session(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staffer@example.com']);
        $session = UserSession::factory()->for($staff)->create();
        $epoch = $staff->auth_epoch;

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('staffer@example.com')->andReturn([]);
        $this->app->instance(LdapGroupLookup::class, $lookup);

        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $staff->refresh();
        $this->assertSame(Role::Public, $staff->role);
        $this->assertSame($epoch + 1, $staff->auth_epoch);
        $this->assertNotNull($session->fresh()->revoked_at);
    }

    private function assertEndedExceptCurrent(User $user, UserSession $other): void
    {
        $this->assertNotNull($other->fresh()->revoked_at);
        $this->assertSame(1, $user->sessions()->whereNull('revoked_at')->count());
        $this->get(route('account.show'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
