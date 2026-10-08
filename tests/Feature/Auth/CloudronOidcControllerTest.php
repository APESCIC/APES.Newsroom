<?php

namespace Tests\Feature\Auth;

use App\Exceptions\Auth\OidcValidationException;
use App\Services\Auth\CloudronOidcProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CloudronOidcControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = [
        'cloudron_oidc_state' => 'expected-state',
        'cloudron_oidc_nonce' => 'expected-nonce',
        'cloudron_oidc_pkce' => 'expected-pkce',
    ];

    public function test_callback_is_denied_when_state_does_not_match(): void
    {
        $this->withSession(self::SESSION);

        $response = $this->get('/auth/cloudron/callback?state=wrong-state&code=abc');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_callback_is_denied_when_no_state_was_stored(): void
    {
        $response = $this->get('/auth/cloudron/callback?state=whatever&code=abc');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_callback_is_denied_when_the_nonce_or_pkce_code_is_missing_from_the_session(): void
    {
        $this->withSession(['cloudron_oidc_state' => 'expected-state']);

        $response = $this->get('/auth/cloudron/callback?state=expected-state&code=abc');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_callback_is_denied_when_no_authorization_code_is_returned(): void
    {
        $this->withSession(self::SESSION);

        $response = $this->get('/auth/cloudron/callback?state=expected-state');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_callback_is_denied_when_the_provider_returns_an_error(): void
    {
        $this->withSession(self::SESSION);

        $response = $this->get('/auth/cloudron/callback?state=expected-state&error=access_denied');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_identity_that_fails_validation_redirects_to_login_instead_of_erroring(): void
    {
        $provider = Mockery::mock(CloudronOidcProvider::class);
        $provider->shouldReceive('exchangeCodeForIdentity')
            ->andThrow(new OidcValidationException('ID token nonce does not match.'));
        $this->app->instance(CloudronOidcProvider::class, $provider);
        $this->withSession(self::SESSION);

        $response = $this->get('/auth/cloudron/callback?state=expected-state&code=abc');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
