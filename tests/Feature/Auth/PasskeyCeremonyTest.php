<?php

namespace Tests\Feature\Auth;

use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\Passkeys\PasskeyCeremony;
use App\Services\Auth\Passkeys\PasskeyRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

class PasskeyCeremonyTest extends TestCase
{
    use RefreshDatabase;

    private PasskeyCeremony $ceremony;

    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceremony = app(PasskeyCeremony::class);
        $this->request = Request::create('/');
        $this->request->setLaravelSession($this->app['session.store']);
    }

    public function test_the_relying_party_follows_the_app_url(): void
    {
        $this->assertSame('localhost', config('passkeys.relying_party.id'));
        $this->assertSame(['http://localhost'], config('passkeys.allowed_origins'));
        $this->assertSame('APES Newsroom', config('passkeys.relying_party.name'));
    }

    public function test_registration_then_sign_in_round_trip(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->authenticator();

        $passkey = $this->register($user, $authenticator, 'Work laptop');

        $this->assertSame($user->id, $passkey->authenticatable_id);
        $this->assertSame('Work laptop', $passkey->name);
        $this->assertSame(Base64UrlSafe::encodeUnpadded($authenticator->credentialId), $passkey->credential_id);
        $this->assertNull($passkey->last_used_at);

        $assertion = $this->ceremony->authenticate($this->request, $authenticator->assert($this->ceremony->authenticationOptions($this->request)));

        $this->assertNotNull($assertion);
        $this->assertTrue($assertion->passkey->is($passkey));
        $this->assertTrue($assertion->userVerified);
        $this->assertNotNull($passkey->fresh()->last_used_at);
    }

    public function test_registration_options_use_an_opaque_user_handle_and_no_attestation(): void
    {
        $user = User::factory()->create();

        $options = json_decode($this->ceremony->registrationOptions($this->request, $user), true);

        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame('none', $options['attestation']);
        $this->assertSame('preferred', $options['authenticatorSelection']['userVerification']);
        $this->assertNotSame((string) $user->id, Base64UrlSafe::decode($options['user']['id']));
        $this->assertSame($user->email, $options['user']['name']);
        $this->assertSame([-7, -257], array_column($options['pubKeyCredParams'], 'alg'));
    }

    public function test_registration_options_exclude_passkeys_already_on_the_account(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->authenticator();
        $this->register($user, $authenticator);

        $options = json_decode($this->ceremony->registrationOptions($this->request, $user->fresh()), true);

        $this->assertSame([Base64UrlSafe::encodeUnpadded($authenticator->credentialId)], array_column($options['excludeCredentials'], 'id'));
    }

    public function test_registration_options_can_only_be_used_once(): void
    {
        $user = User::factory()->create();
        $options = $this->ceremony->registrationOptions($this->request, $user);
        $this->ceremony->register($this->request, $user, $this->authenticator()->register($options), 'First');

        $this->expectException(PasskeyRejected::class);
        $this->ceremony->register($this->request, $user, $this->authenticator()->register($options), 'Second');
    }

    public function test_registration_from_another_origin_is_rejected(): void
    {
        $user = User::factory()->create();
        $options = $this->ceremony->registrationOptions($this->request, $user);

        $this->assertRejected(fn () => $this->ceremony->register(
            $this->request,
            $user,
            (new FakeAuthenticator('https://evil.example'))->register($options),
            'Phished',
        ));
        $this->assertSame(0, Passkey::query()->count());
    }

    public function test_registration_from_a_subdomain_is_rejected(): void
    {
        config(['passkeys.allowed_origins' => ['https://localhost']]);
        $user = User::factory()->create();

        $options = $this->ceremony->registrationOptions($this->request, $user);
        $this->assertRejected(fn () => $this->ceremony->register(
            $this->request,
            $user,
            (new FakeAuthenticator('https://attacker.localhost'))->register($options),
            'Subdomain',
        ));

        $options = $this->ceremony->registrationOptions($this->request, $user);
        $this->ceremony->register($this->request, $user, (new FakeAuthenticator('https://localhost'))->register($options), 'Exact origin');
        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_registration_with_the_wrong_challenge_is_rejected(): void
    {
        $user = User::factory()->create();
        $options = $this->ceremony->registrationOptions($this->request, $user);

        $this->assertRejected(fn () => $this->ceremony->register(
            $this->request,
            $user,
            $this->authenticator()->register($options, ['challenge' => Base64UrlSafe::encodeUnpadded(random_bytes(32))]),
            'Wrong challenge',
        ));
    }

    public function test_a_credential_cannot_be_registered_to_a_second_account(): void
    {
        $credentialId = random_bytes(32);
        $this->register(User::factory()->create(), $this->authenticator($credentialId));

        $other = User::factory()->create();

        $this->assertRejected(fn () => $this->register($other, $this->authenticator($credentialId)));
        $this->assertSame(0, $other->passkeys()->count());
    }

    public function test_sign_in_from_another_origin_is_rejected(): void
    {
        $authenticator = $this->authenticator();
        $this->register(User::factory()->create(), $authenticator);

        $options = $this->ceremony->authenticationOptions($this->request);

        $this->assertNull($this->ceremony->authenticate(
            $this->request,
            $authenticator->assert($options, ['origin' => 'https://evil.example']),
        ));
    }

    public function test_sign_in_with_the_wrong_challenge_is_rejected(): void
    {
        $authenticator = $this->authenticator();
        $this->register(User::factory()->create(), $authenticator);

        $this->ceremony->authenticationOptions($this->request);

        $this->assertNull($this->ceremony->authenticate(
            $this->request,
            $authenticator->assert(json_encode(['challenge' => Base64UrlSafe::encodeUnpadded(random_bytes(32)), 'rpId' => 'localhost'])),
        ));
    }

    public function test_sign_in_options_can_only_be_used_once(): void
    {
        $authenticator = $this->authenticator();
        $this->register(User::factory()->create(), $authenticator);

        $options = $this->ceremony->authenticationOptions($this->request);
        $this->assertNotNull($this->ceremony->authenticate($this->request, $authenticator->assert($options)));

        $this->assertNull($this->ceremony->authenticate($this->request, $authenticator->assert($options)));
    }

    public function test_a_replayed_signature_counter_is_rejected(): void
    {
        $authenticator = $this->authenticator();
        $this->register(User::factory()->create(), $authenticator);

        $this->assertNotNull($this->ceremony->authenticate(
            $this->request,
            $authenticator->assert($this->ceremony->authenticationOptions($this->request), signCount: 5),
        ));

        $this->assertNull($this->ceremony->authenticate(
            $this->request,
            $authenticator->assert($this->ceremony->authenticationOptions($this->request), signCount: 5),
        ));
    }

    public function test_a_signature_from_a_different_key_is_rejected(): void
    {
        $credentialId = random_bytes(32);
        $this->register(User::factory()->create(), $this->authenticator($credentialId));

        $impostor = $this->authenticator($credentialId);
        $impostor->register($this->ceremony->registrationOptions($this->request, User::factory()->create()));

        $this->assertNull($this->ceremony->authenticate(
            $this->request,
            $impostor->assert($this->ceremony->authenticationOptions($this->request)),
        ));
    }

    public function test_an_unknown_credential_is_rejected(): void
    {
        $authenticator = $this->authenticator();
        $authenticator->register($this->ceremony->registrationOptions($this->request, User::factory()->create()));

        $this->assertNull($this->ceremony->authenticate(
            $this->request,
            $authenticator->assert($this->ceremony->authenticationOptions($this->request)),
        ));
    }

    public function test_sign_in_reports_when_the_user_was_not_verified(): void
    {
        $authenticator = $this->authenticator();
        $this->register(User::factory()->create(), $authenticator);

        $assertion = $this->ceremony->authenticate(
            $this->request,
            $authenticator->assert($this->ceremony->authenticationOptions($this->request), flags: FakeAuthenticator::USER_PRESENT),
        );

        $this->assertNotNull($assertion);
        $this->assertFalse($assertion->userVerified);
    }

    public function test_garbage_sign_in_payloads_are_rejected_quietly(): void
    {
        $this->ceremony->authenticationOptions($this->request);

        $this->assertNull($this->ceremony->authenticate($this->request, '{"not":"a credential"}'));
    }

    public function test_deleting_an_account_deletes_its_passkeys(): void
    {
        $user = User::factory()->create();
        $this->register($user, $this->authenticator());

        $user->delete();

        $this->assertSame(0, Passkey::query()->count());
    }

    public function test_security_headers_allow_webauthn_on_this_origin_only(): void
    {
        $response = $this->get('/');

        $this->assertStringContainsString('publickey-credentials-create=(self)', $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('publickey-credentials-get=(self)', $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("connect-src 'self';", $response->headers->get('Content-Security-Policy'));
    }

    private function authenticator(?string $credentialId = null): FakeAuthenticator
    {
        return new FakeAuthenticator('http://localhost', $credentialId);
    }

    private function register(User $user, FakeAuthenticator $authenticator, string $name = 'Passkey'): Passkey
    {
        $options = $this->ceremony->registrationOptions($this->request, $user);

        return $this->ceremony->register($this->request, $user, $authenticator->register($options), $name);
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (PasskeyRejected) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the passkey to be rejected.');
    }
}
