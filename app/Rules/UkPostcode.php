<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UkPostcode implements ValidationRule
{
    public const PATTERN = '/^(GIR ?0AA|[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2})$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, trim($value)) !== 1) {
            $fail('Enter a valid UK postcode, for example LS1 4AP.');
        }
    }
}
