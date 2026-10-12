<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * A software P-256 authenticator that produces real "none" attestations and
 * signed assertions, so tests exercise the full WebAuthn verification path.
 */
final class FakeAuthenticator
{
    public const USER_PRESENT = 0x01;

    public const USER_VERIFIED = 0x04;

    private const ATTESTED_CREDENTIAL_DATA = 0x40;

    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private int $signCount = 0;

    private ?string $userHandle = null;

    public function __construct(public string $origin, ?string $credentialId = null)
    {
        $this->key = self::generateKey();
        $this->credentialId = $credentialId ?? random_bytes(32);
    }

    /**
     * Windows PHP builds often ship without an openssl.cnf, which key
     * generation needs; an empty one is enough.
     */
    private static function generateKey(): OpenSSLAsymmetricKey
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = @openssl_pkey_new($options);

        if ($key === false) {
            $config = tempnam(sys_get_temp_dir(), 'openssl');
            $key = openssl_pkey_new([...$options, 'config' => $config]);
            unlink($config);
        }

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not generate a P-256 key: '.openssl_error_string());
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $clientData  overrides for clientDataJSON
     */
    public function register(string $optionsJson, array $clientData = [], int $flags = self::USER_PRESENT | self::USER_VERIFIED): string
    {
        $options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
        $this->userHandle = Base64UrlSafe::decode($options['user']['id']);

        $authData = hash('sha256', $options['rp']['id'], true)
            .chr($flags | self::ATTESTED_CREDENTIAL_DATA)
            .pack('N', $this->signCount)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestationObject = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return $this->credential([
            'clientDataJSON' => $this->encode($this->clientData('webauthn.create', $options['challenge'], $clientData)),
            'attestationObject' => $this->encode((string) $attestationObject),
            'transports' => ['internal'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $clientData  overrides for clientDataJSON
     */
    public function assert(
        string $optionsJson,
        array $clientData = [],
        int $flags = self::USER_PRESENT | self::USER_VERIFIED,
        ?int $signCount = null,
    ): string {
        $options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
        $this->signCount = $signCount ?? $this->signCount + 1;

        $clientDataJson = $this->clientData('webauthn.get', $options['challenge'], $clientData);
        $authData = hash('sha256', $options['rpId'], true).chr($flags).pack('N', $this->signCount);

        openssl_sign($authData.hash('sha256', $clientDataJson, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->credential([
            'clientDataJSON' => $this->encode($clientDataJson),
            'authenticatorData' => $this->encode($authData),
            'signature' => $this->encode($signature),
            'userHandle' => $this->userHandle === null ? null : $this->encode($this->userHandle),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function credential(array $response): string
    {
        return json_encode([
            'id' => $this->encode($this->credentialId),
            'rawId' => $this->encode($this->credentialId),
            'type' => 'public-key',
            'response' => $response,
            'clientExtensionResults' => (object) [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function clientData(string $type, string $challenge, array $overrides): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
            ...$overrides,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)));
    }

    private function encode(string $bytes): string
    {
        return Base64UrlSafe::encodeUnpadded($bytes);
    }
}
