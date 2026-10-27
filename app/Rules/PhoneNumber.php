<?php

namespace App\Rules;

use App\Support\Account\PhoneNumbers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumbers::toE164($value) === null) {
            $fail('Enter a valid phone number, for example 07700 900123 or +44 7700 900123.');
        }
    }
}
