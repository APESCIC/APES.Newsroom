<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\PendingTwoFactorLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\CreatesPasskeys;
use Tests\Support\FakeAuthenticator;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class PasskeyLoginTest extends TestCase
{
    use CreatesPasskeys, InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_sign_in_options_allow_any_passkey_for_this_site(): void
    {
        $this->postJson(route('passkey-login.options'))
            ->assertOk()
            ->assertJsonPath('rpId', 'localhost')
            ->assertJsonPath('userVerification', 'preferred')
            ->assertJsonMissingPath('allowCredentials.0');
    }

    public function test_a_member_can_sign_in_with_a_passkey(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();
        $passkey = $this->createPasskey($user, 'Phone', $authenticator);

        $this->signInWith($authenticator)
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($passkey->fresh()->last_used_at);

        $audit = AuditLog::query()->where('action', 'auth.passkey_login')->sole();
        $this->assertSame(['passkey_id' => $passkey->id, 'user_verified' => true], $audit->payload);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public function test_passkey_sign_in_returns_to_the_page_the_user_wanted(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Phone', $authenticator);

        $this->get(route('account.security'))->assertRedirect(route('login'));

        $this->signInWith($authenticator)->assertRedirect(route('account.security'));
    }

    public function test_passkey_sign_in_can_remember_the_device(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Phone', $authenticator);

        $this->signInWith($authenticator, remember: true)
            ->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_a_verified_passkey_skips_the_authenticator_app_challenge(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Phone', $authenticator);

        $this->signInWith($authenticator)->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.two_factor_challenged']);
    }

    public function test_a_passkey_without_user_verification_still_needs_the_second_factor(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Security key', $authenticator);

        $this->signInWith($authenticator, flags: FakeAuthenticator::USER_PRESENT)
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_challenged', 'actor_id' => $user->id]);

        $this->post(route('two-factor.challenge.store'), ['code' => $this->totpFor($user)])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_passkey_without_user_verification_signs_in_accounts_without_two_factor(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Security key', $authenticator);

        $this->signInWith($authenticator, flags: FakeAuthenticator::USER_PRESENT)
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_staff_accounts_are_refused_with_the_generic_error(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Phone', $authenticator);
        $user->forceFill(['auth_provider' => 'cloudron_oidc'])->save();

        $this->from(route('login'))
            ->signInWith($authenticator)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['passkey' => 'That passkey could not be used to sign in.']);

        $this->assertGuest();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.passkey_login']);
    }

    public function test_an_invalid_passkey_gets_the_same_generic_error(): void
    {
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey(User::factory()->create(), 'Phone', $authenticator);

        $options = $this->postJson(route('passkey-login.options'))->getContent();

        $this->from(route('login'))
            ->post(route('passkey-login.store'), ['credential' => $authenticator->assert($options, ['origin' => 'https://evil.example'])])
            ->assertSessionHasErrors(['passkey' => 'That passkey could not be used to sign in.']);

        $this->assertGuest();
    }

    public function test_a_signed_response_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Phone', $authenticator);

        $options = $this->postJson(route('passkey-login.options'))->getContent();
        $credential = $authenticator->assert($options);

        $this->post(route('passkey-login.store'), ['credential' => $credential])->assertRedirect(route('home'));
        $this->post('/logout');

        $this->post(route('passkey-login.store'), ['credential' => $credential])
            ->assertSessionHasErrors('passkey');
        $this->assertGuest();
    }

    public function test_the_two_factor_challenge_offers_and_accepts_a_passkey(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $authenticator = $this->fakeAuthenticator();
        $this->createPasskey($user, 'Phone', $authenticator);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->get(route('two-factor.challenge'))
            ->assertInertia(fn (Assert $page) => $page->where('has_passkeys', true));

        $options = $this->postJson(route('two-factor.challenge.passkey-options'))
            ->assertOk()
            ->assertJsonPath('allowCredentials.0.id', Base64UrlSafe::encodeUnpadded($authenticator->credentialId))
            ->getContent();

        $this->post(route('two-factor.challenge.passkey'), ['credential' => $authenticator->assert($options, flags: FakeAuthenticator::USER_PRESENT)])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $passed = AuditLog::query()->where('action', 'auth.two_factor_passed')->sole();
        $this->assertSame('passkey', $passed->payload['method']);
    }

    public function test_the_challenge_refuses_someone_elses_passkey(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $this->createPasskey($user, 'Phone');

        $intruder = User::factory()->create();
        $intruderKey = $this->fakeAuthenticator();
        $this->createPasskey($intruder, 'Intruder', $intruderKey);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $options = $this->postJson(route('two-factor.challenge.passkey-options'))->assertOk()->getContent();

        $this->post(route('two-factor.challenge.passkey'), ['credential' => $intruderKey->assert($options)])
            ->assertSessionHasErrors('passkey');

        $this->assertGuest();
        $this->assertSame(1, session(PendingTwoFactorLogin::SESSION_KEY.'.attempts'));
    }

    public function test_the_challenge_hides_passkeys_for_accounts_without_any(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get(route('two-factor.challenge'))
            ->assertInertia(fn (Assert $page) => $page->where('has_passkeys', false));

        $this->postJson(route('two-factor.challenge.passkey-options'))->assertNotFound();
    }

    public function test_signed_in_users_cannot_use_the_guest_passkey_endpoints(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('passkey-login.options'))
            ->assertRedirect();
    }

    private function signInWith(FakeAuthenticator $authenticator, bool $remember = false, int $flags = FakeAuthenticator::USER_PRESENT | FakeAuthenticator::USER_VERIFIED): TestResponse
    {
        $options = $this->postJson(route('passkey-login.options'))->getContent();

        return $this->post(route('passkey-login.store'), [
            'credential' => $authenticator->assert($options, flags: $flags),
            'remember' => $remember,
        ]);
    }
}
