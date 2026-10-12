<?php

namespace App\Services\Auth\Passkeys;

use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Returns the options without touching the session; PasskeyCeremony owns
 * where the single-use challenge is kept. Passing a user limits the
 * ceremony to that account's passkeys.
 */
class GeneratePasskeyAuthenticationOptions extends GeneratePasskeyAuthenticationOptionsAction
{
    public function execute(?HasPasskeys $authenticatable = null): string
    {
        return Serializer::make()->toJson(new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: $authenticatable === null ? [] : $authenticatable->passkeys
                ->map(fn ($passkey) => PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $passkey->data->publicKeyCredentialId,
                ))
                ->all(),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 300_000,
        ));
    }
}
