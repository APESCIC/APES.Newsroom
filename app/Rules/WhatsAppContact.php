<?php

namespace App\Rules;

use App\Support\Account\PhoneNumbers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A WhatsApp contact is either a phone number or a username, depending on
 * the type the user picked.
 */
class WhatsAppContact implements ValidationRule
{
    public const USERNAME_PATTERN = '/^(?=.*[a-z])[a-z0-9._]{3,35}$/i';

    public function __construct(private readonly ?string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a WhatsApp number or username.');

            return;
        }

        if ($this->type === 'username') {
            if (preg_match(self::USERNAME_PATTERN, ltrim(trim($value), '@')) !== 1) {
                $fail('Enter a WhatsApp username of 3 to 35 letters, numbers, dots or underscores.');
            }

            return;
        }

        if (PhoneNumbers::toE164($value) === null) {
            $fail('Enter a valid WhatsApp number, for example 07700 900123 or +44 7700 900123.');
        }
    }
}
