<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\OidcValidationException;
use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Thin wrapper around Cloudron's discovery-document-based OIDC provider.
 *
 * Cloudron exposes a generic OIDC issuer (discovery URL, issuer, client
 * id/secret) rather than a named provider, so this builds a
 * league/oauth2-client GenericProvider from the discovery document
 * instead of depending on a fixed-provider package like Socialite.
 *
 * The authorization code flow uses PKCE (S256) and a nonce, and the
 * identity is only trusted once the ID token's signature (JWKS), issuer,
 * audience, expiry and nonce check out and its subject matches userinfo.
 */
class CloudronOidcProvider
{
    private const JWKS_CACHE_KEY = 'cloudron_oidc.jwks';

    private ?GenericProvider $provider = null;

    public function authorizationUrl(string $nonce): string
    {
        return $this->provider()->getAuthorizationUrl([
            'scope' => ['openid', 'email', 'profile'],
            'nonce' => $nonce,
        ]);
    }

    public function getState(): string
    {
        return $this->provider()->getState();
    }

    public function getPkceCode(): string
    {
        return (string) $this->provider()->getPkceCode();
    }

    /**
     * @throws OidcValidationException
     */
    public function exchangeCodeForIdentity(string $code, string $pkceCode, string $nonce): StaffOidcIdentity
    {
        $provider = $this->provider();
        $provider->setPkceCode($pkceCode);

        $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        $idToken = $token->getValues()['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw new OidcValidationException('The token response did not include an ID token.');
        }

        $claims = $this->validateIdToken($idToken, $nonce);
        $owner = $provider->getResourceOwner($token)->toArray();

        $sub = (string) ($owner['sub'] ?? '');
        $email = (string) ($owner['email'] ?? '');

        if ($sub === '' || $email === '') {
            throw new OidcValidationException('Userinfo did not include a subject and email.');
        }

        if (! hash_equals((string) $claims->sub, $sub)) {
            throw new OidcValidationException('ID token subject does not match userinfo subject.');
        }

        return new StaffOidcIdentity(
            sub: $sub,
            email: $email,
            name: (string) ($owner['name'] ?? $email),
            emailVerified: filter_var($owner['email_verified'] ?? $claims->email_verified ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    private function validateIdToken(string $idToken, string $nonce): stdClass
    {
        $claims = $this->decodeIdToken($idToken);

        $expectedIssuer = config('services.cloudron_oidc.issuer') ?: ($this->discoveryDocument()['issuer'] ?? '');

        if (! is_string($claims->iss ?? null) || rtrim($claims->iss, '/') !== rtrim((string) $expectedIssuer, '/')) {
            throw new OidcValidationException('ID token issuer is not the configured issuer.');
        }

        if (! in_array(config('services.cloudron_oidc.client_id'), (array) ($claims->aud ?? []), true)) {
            throw new OidcValidationException('ID token audience does not include this client.');
        }

        if (! isset($claims->exp)) {
            throw new OidcValidationException('ID token has no expiry.');
        }

        if (! is_string($claims->nonce ?? null) || ! hash_equals($nonce, $claims->nonce)) {
            throw new OidcValidationException('ID token nonce does not match.');
        }

        if (! is_string($claims->sub ?? null) || $claims->sub === '') {
            throw new OidcValidationException('ID token has no subject.');
        }

        return $claims;
    }

    /**
     * Verifies the signature and time claims. Retries once with a fresh
     * JWKS so a key rotation does not lock staff out until the cache expires.
     */
    private function decodeIdToken(string $idToken, bool $refreshedKeys = false): stdClass
    {
        try {
            return JWT::decode($idToken, JWK::parseKeySet($this->jwks(), 'RS256'));
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $e) {
            if (! $refreshedKeys) {
                Cache::forget(self::JWKS_CACHE_KEY);

                return $this->decodeIdToken($idToken, refreshedKeys: true);
            }

            throw new OidcValidationException('ID token could not be verified: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        $jwksUri = $this->discoveryDocument()['jwks_uri'] ?? null;

        if (! is_string($jwksUri) || $jwksUri === '') {
            throw new OidcValidationException('The discovery document has no jwks_uri.');
        }

        return Cache::remember(
            self::JWKS_CACHE_KEY,
            now()->addHour(),
            fn () => Http::get($jwksUri)->throw()->json(),
        );
    }

    private function provider(): GenericProvider
    {
        if ($this->provider) {
            return $this->provider;
        }

        $discovery = $this->discoveryDocument();

        return $this->provider = new GenericProvider([
            'clientId' => config('services.cloudron_oidc.client_id'),
            'clientSecret' => config('services.cloudron_oidc.client_secret'),
            'redirectUri' => route('cloudron.callback'),
            'urlAuthorize' => $discovery['authorization_endpoint'],
            'urlAccessToken' => $discovery['token_endpoint'],
            'urlResourceOwnerDetails' => $discovery['userinfo_endpoint'],
            // OIDC requires space-delimited scopes; league/oauth2-client defaults to commas.
            'scopeSeparator' => ' ',
            'pkceMethod' => AbstractProvider::PKCE_METHOD_S256,
        ], [
            'httpClient' => Http::buildClient(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function discoveryDocument(): array
    {
        $discoveryUrl = config('services.cloudron_oidc.discovery_url');

        if (! $discoveryUrl) {
            throw new RuntimeException('Cloudron OIDC discovery URL is not configured.');
        }

        return Cache::remember(
            'cloudron_oidc.discovery_document',
            now()->addHour(),
            fn () => Http::get($discoveryUrl)->throw()->json(),
        );
    }
}
