<?php

namespace App\Services\Auth\Passkeys;

use Cose\Algorithms;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;

/**
 * Adds ES256/RS256 algorithms and excludes the account's existing passkeys
 * so the same authenticator is not registered twice.
 */
class GeneratePasskeyRegisterOptions extends GeneratePasskeyRegisterOptionsAction
{
    public function execute(HasPasskeys $authenticatable, bool $asJson = true): string|PublicKeyCredentialCreationOptions
    {
        $options = new PublicKeyCredentialCreationOptions(
            rp: $this->relatedPartyEntity(),
            user: $this->generateUserEntity($authenticatable),
            challenge: $this->challenge(),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            authenticatorSelection: $this->authenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $authenticatable->passkeys
                ->map(fn ($passkey) => PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $passkey->data->publicKeyCredentialId,
                ))
                ->all(),
            timeout: 300_000,
        );

        return $asJson ? Serializer::make()->toJson($options) : $options;
    }

    protected function challenge(): string
    {
        return random_bytes(32);
    }
}
