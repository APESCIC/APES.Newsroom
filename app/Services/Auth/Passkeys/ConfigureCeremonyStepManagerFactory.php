<?php

namespace App\Services\Auth\Passkeys;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Only the exact configured origins may complete a ceremony. Without this
 * the library accepts any https subdomain of the relying party ID.
 */
class ConfigureCeremonyStepManagerFactory extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();
        $factory->setAllowedOrigins(config('passkeys.allowed_origins'));

        return $factory;
    }
}
