<?php

use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\Passkeys\ConfigureCeremonyStepManagerFactory;
use App\Services\Auth\Passkeys\GeneratePasskeyAuthenticationOptions;
use App\Services\Auth\Passkeys\GeneratePasskeyRegisterOptions;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

return [
    'redirect_to_after_login' => '/',

    'actions' => [
        'generate_passkey_register_options' => GeneratePasskeyRegisterOptions::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => GeneratePasskeyAuthenticationOptions::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        'configure_ceremony_step_manager_factory' => ConfigureCeremonyStepManagerFactory::class,
    ],

    /*
     * The relying party ID must be the site's host name, never an IP
     * address. When unset it is taken from app.url at boot, after Cloudron
     * has supplied the real origin. Locally, passkeys only work on
     * http://localhost.
     */
    'relying_party' => [
        'name' => env('PASSKEYS_RP_NAME', 'APES Newsroom'),
        'id' => env('PASSKEYS_RP_ID'),
        'icon' => null,
    ],

    /*
     * Exact origins (scheme, host and port) allowed to complete a passkey
     * ceremony. When empty, the origin of app.url is used.
     */
    'allowed_origins' => array_values(array_filter(explode(',', (string) env('PASSKEYS_ALLOWED_ORIGINS', '')))),

    'models' => [
        'passkey' => Passkey::class,
        'authenticatable' => User::class,
    ],
];
