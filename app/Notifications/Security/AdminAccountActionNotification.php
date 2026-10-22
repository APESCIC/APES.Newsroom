<?php

namespace App\Notifications\Security;

use InvalidArgumentException;

/**
 * Tells a user an administrator changed their account's security.
 */
class AdminAccountActionNotification extends SecurityNotification
{
    public const SIGNED_OUT = 'signed_out';

    public const PASSWORD_RESET = 'password_reset';

    public const TWO_FACTOR_RESET = 'two_factor_reset';

    public const PASSKEYS_REMOVED = 'passkeys_removed';

    public function __construct(public readonly string $action)
    {
        if (! in_array($action, [self::SIGNED_OUT, self::PASSWORD_RESET, self::TWO_FACTOR_RESET, self::PASSKEYS_REMOVED], true)) {
            throw new InvalidArgumentException("Unknown admin account action [{$action}].");
        }
    }

    protected function subject(): string
    {
        return match ($this->action) {
            self::SIGNED_OUT => 'you were signed out by an administrator',
            self::PASSWORD_RESET => 'an administrator reset your password',
            self::TWO_FACTOR_RESET => 'an administrator turned off two-factor sign-in',
            self::PASSKEYS_REMOVED => 'an administrator removed your passkeys',
        };
    }

    protected function summary(): array
    {
        return match ($this->action) {
            self::SIGNED_OUT => [
                'An administrator signed your APES Newsroom account out of every device to protect it.',
                'You can sign in again as normal.',
            ],
            self::PASSWORD_RESET => [
                'An administrator reset the password on your APES Newsroom account to protect it, and signed you out everywhere.',
                'We have sent a separate email with a link to choose a new password.',
            ],
            self::TWO_FACTOR_RESET => [
                'An administrator turned off two-factor sign-in (authenticator app and recovery codes) on your APES Newsroom account.',
                'Please set it up again from your account security page.',
            ],
            self::PASSKEYS_REMOVED => [
                'An administrator removed every passkey from your APES Newsroom account.',
                'You can add a new passkey from your account security page.',
            ],
        };
    }
}
