<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\PendingTwoFactorLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_password_sign_in_without_two_factor_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_sign_in_with_two_factor_holds_the_user_at_the_challenge(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_challenged', 'actor_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login']);

        $this->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));

        $this->get(route('account.show'))->assertRedirect(route('login'));
    }

    public function test_a_valid_code_completes_password_sign_in(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session(PendingTwoFactorLogin::SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_passed', 'actor_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public function test_the_challenge_returns_to_the_page_the_user_wanted(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->get(route('account.security'))->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('account.security'));
    }

    public function test_a_magic_link_cannot_bypass_two_factor(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->get($this->magicLinkFor($user))
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->get(route('account.show'))->assertRedirect(route('login'));

        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('account.show'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_passed', 'actor_id' => $user->id]);
    }

    public function test_a_magic_link_without_two_factor_still_signs_in(): void
    {
        $user = User::factory()->create();

        $this->get($this->magicLinkFor($user))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_code_keeps_the_user_signed_out(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->from(route('two-factor.challenge'))
            ->post(route('two-factor.challenge.store'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.challenge'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_failed', 'actor_id' => $user->id]);
    }

    public function test_too_many_wrong_codes_abandon_the_sign_in(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        for ($i = 1; $i < PendingTwoFactorLogin::MAX_ATTEMPTS; $i++) {
            $this->from(route('two-factor.challenge'))
                ->post(route('two-factor.challenge.store'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->post(route('two-factor.challenge.store'), ['code' => '000000'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_challenge_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->session([PendingTwoFactorLogin::SESSION_KEY.'.attempts' => -100]);

        for ($i = 0; $i < 5; $i++) {
            $this->from(route('two-factor.challenge'))->post(route('two-factor.challenge.store'), ['code' => '000000']);
        }

        $this->from(route('two-factor.challenge'))
            ->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_a_code_cannot_be_replayed_for_a_second_sign_in(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $code = $this->totpFor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertRedirect(route('home'));

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->from(route('two-factor.challenge'))
            ->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_an_expired_challenge_sends_the_user_back_to_sign_in(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->travel(6)->minutes();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_challenge_page_needs_a_pending_sign_in(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_remember_me_is_kept_through_the_challenge(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());

        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_a_wrong_password_never_reaches_the_challenge(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertNull(session(PendingTwoFactorLogin::SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed']);
    }

    private function magicLinkFor(User $user): string
    {
        $token = Str::random(64);
        $user->magicLinkTokens()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        return URL::temporarySignedRoute('magic-link.consume', now()->addMinutes(15), ['token' => $token]);
    }
}
