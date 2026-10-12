<?php

namespace App\Services\Auth\Passkeys;

use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Returns the options without touching the session; PasskeyCeremony owns
 * where the single-use challenge is kept.
 */
class GeneratePasskeyAuthenticationOptions extends GeneratePasskeyAuthenticationOptionsAction
{
    public function execute(): string
    {
        return Serializer::make()->toJson(new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 300_000,
        ));
    }
}
