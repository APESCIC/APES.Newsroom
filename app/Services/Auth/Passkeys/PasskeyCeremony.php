<?php

namespace App\Services\Auth\Passkeys;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredential;

/**
 * Registration and sign-in ceremonies. Each challenge lives in the session
 * and is removed on first use, so a response can never be replayed.
 */
class PasskeyCeremony
{
    public const REGISTRATION_KEY = 'passkeys.registration_options';

    public const AUTHENTICATION_KEY = 'passkeys.authentication_options';

    public function registrationOptions(Request $request, User $user): string
    {
        $options = Config::getAction('generate_passkey_register_options', GeneratePasskeyRegisterOptionsAction::class)
            ->execute($user);

        $request->session()->put(self::REGISTRATION_KEY, $options);

        return $options;
    }

    /**
     * @throws PasskeyRejected
     */
    public function register(Request $request, User $user, string $credentialJson, string $name): Passkey
    {
        $options = $request->session()->pull(self::REGISTRATION_KEY);

        if (! is_string($options)) {
            throw new PasskeyRejected('No passkey setup is in progress.');
        }

        return DB::transaction(function () use ($user, $credentialJson, $options, $name): Passkey {
            try {
                /** @var Passkey $passkey */
                $passkey = Config::getAction('store_passkey', StorePasskeyAction::class)
                    ->execute($user, $credentialJson, $options, Config::getRelyingPartyId(), ['name' => $name]);
            } catch (Throwable $exception) {
                throw new PasskeyRejected('The passkey could not be verified.', previous: $exception);
            }

            if (Passkey::query()->where('credential_id', $passkey->credential_id)->whereKeyNot($passkey->getKey())->exists()) {
                throw new PasskeyRejected('This passkey is already registered.');
            }

            return $passkey;
        });
    }

    /**
     * @param  User|null  $for  limit the ceremony to this account's passkeys
     */
    public function authenticationOptions(Request $request, ?User $for = null): string
    {
        $options = Config::getAction('generate_passkey_authentication_options', GeneratePasskeyAuthenticationOptionsAction::class)
            ->execute($for);

        $request->session()->put(self::AUTHENTICATION_KEY, $options);

        return $options;
    }

    /**
     * Verify a sign-in assertion. Returns null for anything that does not
     * check out, without saying why.
     */
    public function authenticate(Request $request, string $credentialJson): ?PasskeyAssertion
    {
        $options = $request->session()->pull(self::AUTHENTICATION_KEY);

        if (! is_string($options)) {
            return null;
        }

        try {
            /** @var Passkey|null $passkey */
            $passkey = Config::getAction('find_passkey', FindPasskeyToAuthenticateAction::class)
                ->execute($credentialJson, $options);

            if (! $passkey) {
                return null;
            }

            /** @var PublicKeyCredential $credential */
            $credential = Serializer::make()->fromJson($credentialJson, PublicKeyCredential::class);
            $response = $credential->response;
        } catch (Throwable) {
            return null;
        }

        return new PasskeyAssertion(
            $passkey,
            $response instanceof AuthenticatorAssertionResponse && $response->authenticatorData->isUserVerified(),
        );
    }
}
