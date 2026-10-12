<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\SessionTracker;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_signing_in_with_remember_me_sets_a_thirty_day_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);

        $cookie = $this->recallerCookie($response);
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $cookie->getExpiresTime(), 5);
        $this->assertTrue(UserSession::query()->sole()->remembered);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public function test_remember_duration_is_configurable(): void
    {
        config(['auth.guards.web.remember' => 1440]);
        Auth::forgetGuards();
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);

        $this->assertEqualsWithDelta(now()->addDay()->getTimestamp(), $this->recallerCookie($response)?->getExpiresTime(), 5);
    }

    public function test_signing_in_without_remember_me_sets_no_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNull($this->recallerCookie($response));
        $this->assertFalse(UserSession::query()->sole()->remembered);
    }

    public function test_a_remembered_browser_is_signed_back_in_on_the_same_session_record(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        $recaller = $response->getCookie(Auth::guard('web')->getRecallerName());
        $device = $response->getCookie(SessionTracker::DEVICE_COOKIE);

        $this->startNewBrowserSession();

        $this->withCookie($recaller->getName(), $recaller->getValue())
            ->withCookie($device->getName(), $device->getValue())
            ->get(route('account.show'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, UserSession::query()->count());
        $this->assertTrue(UserSession::query()->sole()->remembered);
    }

    public function test_remember_me_stops_working_after_other_sessions_are_signed_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        $recaller = $response->getCookie(Auth::guard('web')->getRecallerName());

        app(UserSessionRevoker::class)->revoke($user->fresh());
        $this->startNewBrowserSession();

        $this->withCookie($recaller->getName(), $recaller->getValue())
            ->get(route('account.show'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_page_offers_remember_me_for_the_configured_duration(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->component('Auth/Login')->where('rememberDays', 30));
    }

    private function recallerCookie(TestResponse $response): ?Cookie
    {
        return $response->getCookie(Auth::guard('web')->getRecallerName(), decrypt: false);
    }

    private function startNewBrowserSession(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }
}
