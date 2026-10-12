<?php

namespace Tests\Feature\Account;

use App\Http\Middleware\EnsureAuthEpoch;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\SessionTracker;
use App\Support\IpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_security_page_lists_live_sessions_with_this_device_first(): void
    {
        $user = User::factory()->create();
        $phone = UserSession::factory()->for($user)->create(['last_seen_at' => now()->subMinutes(10)]);
        UserSession::factory()->for($user)->revoked()->create();
        UserSession::factory()->for($user)->create(['last_seen_at' => now()->subDays(2)]);
        UserSession::factory()->create();

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Security')
                ->has('sessions', 2)
                ->where('sessions.0.current', true)
                ->where('sessions.1.id', $phone->id)
                ->where('sessions.1.current', false)
                ->where('sessions.1.browser', 'Mobile Safari')
                ->where('sessions.1.platform', 'iOS')
                ->where('sessions.1.ip', '203.0.113.x')
                ->missing('sessions.1.user_agent')
                ->missing('sessions.1.session_key_hash'));
    }

    public function test_remembered_sessions_stay_listed_while_their_cookie_is_valid(): void
    {
        $user = User::factory()->create();
        UserSession::factory()->for($user)->remembered()->create(['last_seen_at' => now()->subDays(10)]);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page->has('sessions', 2));
    }

    public function test_user_can_sign_out_another_session(): void
    {
        $user = User::factory()->create();
        $other = UserSession::factory()->for($user)->create();
        $rememberToken = $user->remember_token;

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('account.sessions.destroy', $other))
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'session-ended');

        $this->assertNotNull($other->fresh()->revoked_at);
        $this->assertSame($rememberToken, $user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.session_ended', 'actor_id' => $user->id]);
    }

    public function test_signing_out_a_remembered_session_invalidates_remember_me(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $other = UserSession::factory()->for($user)->remembered()->create();

        $this->actingAs($user)->delete(route('account.sessions.destroy', $other));

        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
    }

    public function test_the_signed_out_session_is_rejected_on_its_next_request(): void
    {
        $user = User::factory()->create();

        // The "other device" signs in first, then the user ends it from here.
        $this->actingAs($user)->get(route('account.show'))->assertOk();
        $otherDevice = UserSession::query()->sole();
        $otherDeviceKey = session(SessionTracker::SESSION_KEY);

        $this->flushSession();
        $this->actingAs($user)->delete(route('account.sessions.destroy', $otherDevice))
            ->assertSessionHas('status', 'session-ended');

        $this->flushSession();
        $this->actingAs($user)
            ->withSession([SessionTracker::SESSION_KEY => $otherDeviceKey])
            ->get(route('account.show'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_cannot_sign_out_someone_elses_session(): void
    {
        $user = User::factory()->create();
        $foreign = UserSession::factory()->create();

        $this->actingAs($user)->delete(route('account.sessions.destroy', $foreign))->assertNotFound();

        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_this_device_must_use_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'));
        $current = UserSession::query()->sole();

        $this->from(route('account.security'))
            ->delete(route('account.sessions.destroy', $current))
            ->assertSessionHasErrors('session');

        $this->assertNull($current->fresh()->revoked_at);
    }

    public function test_signing_out_all_other_sessions_requires_recent_authentication(): void
    {
        $user = User::factory()->create();
        $other = UserSession::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('account.sessions.destroy-others'))
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertNull($other->fresh()->revoked_at);
    }

    public function test_signing_out_all_other_sessions_keeps_this_one(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $others = UserSession::factory()->for($user)->count(2)->create();
        $epoch = $user->auth_epoch;

        $this->actingAs($user)->withRecentAuthentication()->get(route('account.show'));
        $current = UserSession::query()->whereKeyNot($others->modelKeys())->sole();

        $this->from(route('account.security'))
            ->delete(route('account.sessions.destroy-others'))
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'other-sessions-ended')
            ->assertSessionHas(EnsureAuthEpoch::SESSION_KEY, $epoch + 1);

        $user->refresh();
        $this->assertSame($epoch + 1, $user->auth_epoch);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertNull($current->fresh()->revoked_at);
        $others->each(fn (UserSession $session) => $this->assertNotNull($session->fresh()->revoked_at));
        $this->get(route('account.show'))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.other_sessions_ended', 'actor_id' => $user->id]);
    }

    public function test_ip_addresses_are_masked_for_display(): void
    {
        $this->assertSame('203.0.113.x', IpAddress::mask('203.0.113.24'));
        $this->assertSame('2001:db8:85a3:x', IpAddress::mask('2001:0db8:85a3:0000:0000:8a2e:0370:7334'));
        $this->assertSame('2001:db8:0:x', IpAddress::mask('2001:db8::1'));
        $this->assertNull(IpAddress::mask('not-an-ip'));
        $this->assertNull(IpAddress::mask(null));
    }
}
