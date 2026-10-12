<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Spatie\LaravelPasskeys\Models\Passkey as BasePasskey;
use Spatie\LaravelPasskeys\Support\CredentialRecordConverter;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialSource;

/**
 * Credential IDs are random bytes; the package's default encoding drops
 * invalid UTF-8 sequences, so two IDs could collide. Store them as
 * unpadded base64url instead.
 */
class Passkey extends BasePasskey
{
    protected $hidden = ['data', 'credential_id'];

    public static function encodeCredentialId(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }

    public function data(): Attribute
    {
        $serializer = Serializer::make();

        return new Attribute(
            get: fn (string $value): PublicKeyCredentialSource => CredentialRecordConverter::toPublicKeyCredentialSource(
                $serializer->fromJson($value, PublicKeyCredentialSource::class)
            ),
            set: fn (PublicKeyCredentialSource $value) => [
                'credential_id' => static::encodeCredentialId($value->publicKeyCredentialId),
                'data' => $serializer->toJson($value),
            ],
        );
    }
}
