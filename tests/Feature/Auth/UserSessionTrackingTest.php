<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\SessionTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSessionTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_signing_in_records_the_session_and_device(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->withHeader('User-Agent', self::CHROME_ON_WINDOWS)
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $session = UserSession::query()->sole();
        $key = session(SessionTracker::SESSION_KEY);

        $this->assertSame($user->id, $session->user_id);
        $this->assertSame('Chrome', $session->browser);
        $this->assertSame('Windows', $session->platform);
        $this->assertSame('desktop', $session->device_type);
        $this->assertSame('127.0.0.1', $session->ip_address);
        $this->assertFalse($session->remembered);
        $this->assertNull($session->revoked_at);
        $this->assertIsString($key);
        $this->assertSame(hash('sha256', $key), $session->session_key_hash);
        $this->assertDatabaseMissing('user_sessions', ['session_key_hash' => $key]);
    }

    public function test_last_seen_is_updated_at_most_every_five_minutes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'))->assertOk();
        $session = UserSession::query()->sole();
        $firstSeen = $session->last_seen_at;

        $this->travel(4)->minutes();
        $this->get(route('account.show'))->assertOk();
        $this->assertTrue($session->fresh()->last_seen_at->equalTo($firstSeen));

        $this->travel(2)->minutes();
        $this->get(route('account.show'))->assertOk();
        $this->assertTrue($session->fresh()->last_seen_at->greaterThan($firstSeen));
        $this->assertSame(1, UserSession::query()->count());
    }

    public function test_sessions_from_before_tracking_get_a_record_on_their_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'))->assertOk();

        $this->assertSame(1, $user->sessions()->count());
        $this->assertNotNull(session(SessionTracker::SESSION_KEY));
    }

    public function test_signing_out_marks_the_session_ended(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'));
        $session = UserSession::query()->sole();

        $this->post(route('logout'))->assertRedirect();

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertGuest();
    }

    public function test_an_ended_session_is_rejected_on_its_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'))->assertOk();
        UserSession::query()->sole()->update(['revoked_at' => now()]);

        $this->get(route('account.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_session_key_with_no_record_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([SessionTracker::SESSION_KEY => 'not-a-known-session'])
            ->get(route('account.show'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_session_belonging_to_another_user_is_rejected(): void
    {
        $user = User::factory()->create();
        $key = str_repeat('k', 64);
        UserSession::factory()->create(['session_key_hash' => hash('sha256', $key)]);

        $this->actingAs($user)
            ->withSession([SessionTracker::SESSION_KEY => $key])
            ->get(route('account.show'))
            ->assertRedirect(route('login'));
    }
}
