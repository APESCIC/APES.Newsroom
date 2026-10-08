<?php

namespace Tests\Feature\Auth;

use App\Exceptions\Auth\OidcValidationException;
use App\Services\Auth\CloudronOidcProvider;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class CloudronOidcProviderTest extends TestCase
{
    private const ISSUER = 'https://sso.test';

    private const CLIENT_ID = 'newsroom-client';

    private const NONCE = 'expected-nonce';

    private OpenSSLAsymmetricKey $signingKey;

    /** @var array<string, mixed> */
    private array $userinfo = ['sub' => 'staff-sub', 'email' => 'staffer@example.com', 'name' => 'Staffer', 'email_verified' => true];

    private ?string $idToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudron_oidc.discovery_url' => self::ISSUER.'/.well-known/openid-configuration',
            'services.cloudron_oidc.issuer' => self::ISSUER,
            'services.cloudron_oidc.client_id' => self::CLIENT_ID,
            'services.cloudron_oidc.client_secret' => 'client-secret',
        ]);

        $this->signingKey = $this->newRsaKey();

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/auth',
                'token_endpoint' => self::ISSUER.'/token',
                'userinfo_endpoint' => self::ISSUER.'/me',
                'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            self::ISSUER.'/jwks' => fn () => Http::response(['keys' => [$this->publicJwk($this->signingKey)]]),
            self::ISSUER.'/token' => fn () => Http::response(array_filter([
                'access_token' => 'access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'id_token' => $this->idToken,
            ])),
            self::ISSUER.'/me' => fn () => Http::response($this->userinfo),
        ]);
    }

    public function test_authorization_url_uses_pkce_s256_and_a_nonce(): void
    {
        $provider = app(CloudronOidcProvider::class);

        parse_str((string) parse_url($provider->authorizationUrl(self::NONCE), PHP_URL_QUERY), $query);

        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(self::NONCE, $query['nonce']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $provider->getPkceCode(), true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
    }

    public function test_a_valid_id_token_yields_the_identity_and_sends_the_pkce_verifier(): void
    {
        $this->idToken = $this->signedIdToken();

        $identity = app(CloudronOidcProvider::class)->exchangeCodeForIdentity('auth-code', 'pkce-verifier', self::NONCE);

        $this->assertSame('staff-sub', $identity->sub);
        $this->assertSame('staffer@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        Http::assertSent(fn (Request $request) => $request->url() === self::ISSUER.'/token'
            && $request['code_verifier'] === 'pkce-verifier');
    }

    public function test_a_missing_id_token_is_rejected(): void
    {
        $this->assertRejected('did not include an ID token');
    }

    public function test_a_missing_nonce_is_rejected(): void
    {
        $this->idToken = $this->signedIdToken(['nonce' => null]);

        $this->assertRejected('nonce');
    }

    public function test_a_wrong_nonce_is_rejected(): void
    {
        $this->idToken = $this->signedIdToken(['nonce' => 'attacker-nonce']);

        $this->assertRejected('nonce');
    }

    public function test_a_wrong_audience_is_rejected(): void
    {
        $this->idToken = $this->signedIdToken(['aud' => 'some-other-client']);

        $this->assertRejected('audience');
    }

    public function test_a_wrong_issuer_is_rejected(): void
    {
        $this->idToken = $this->signedIdToken(['iss' => 'https://evil.test']);

        $this->assertRejected('issuer');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->idToken = $this->signedIdToken(['iat' => time() - 7200, 'exp' => time() - 3600]);

        $this->assertRejected('could not be verified');
    }

    public function test_a_token_signed_by_another_key_is_rejected(): void
    {
        $this->idToken = $this->signedIdToken([], $this->newRsaKey());

        $this->assertRejected('could not be verified');
    }

    public function test_a_subject_mismatch_with_userinfo_is_rejected(): void
    {
        $this->idToken = $this->signedIdToken(['sub' => 'someone-else']);

        $this->assertRejected('subject does not match');
    }

    private function assertRejected(string $reason): void
    {
        try {
            app(CloudronOidcProvider::class)->exchangeCodeForIdentity('auth-code', 'pkce-verifier', self::NONCE);
            $this->fail('Expected the ID token to be rejected.');
        } catch (OidcValidationException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides  null values remove the claim
     */
    private function signedIdToken(array $overrides = [], ?OpenSSLAsymmetricKey $key = null): string
    {
        $claims = array_filter(array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'staff-sub',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => self::NONCE,
        ], $overrides), fn ($value) => $value !== null);

        openssl_pkey_export($key ?? $this->signingKey, $pem, null, ['config' => $this->opensslConfig()]);

        return JWT::encode($claims, $pem, 'RS256', 'test-key');
    }

    /**
     * @return array<string, string>
     */
    private function publicJwk(OpenSSLAsymmetricKey $key): array
    {
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return ['kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256', 'n' => $encode($rsa['n']), 'e' => $encode($rsa['e'])];
    }

    private function newRsaKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $this->opensslConfig(),
        ]);

        $this->assertNotFalse($key, 'OpenSSL could not generate an RSA test key.');

        return $key;
    }

    /**
     * Windows PHP builds often ship without a default openssl.cnf, which
     * makes key generation fail; an empty config file is enough.
     */
    private function opensslConfig(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'newsroom-test-openssl.cnf';

        if (! is_file($path)) {
            file_put_contents($path, '');
        }

        return $path;
    }
}
