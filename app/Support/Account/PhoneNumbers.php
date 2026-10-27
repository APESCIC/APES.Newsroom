<?php

namespace App\Support\Account;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\ValidationResult;

final class PhoneNumbers
{
    /**
     * National numbers without a country code are read as UK numbers.
     */
    public const DEFAULT_REGION = 'GB';

    /**
     * E.164 form (+447700900123), or null when the input is not a valid number.
     */
    public static function toE164(string $raw, string $region = self::DEFAULT_REGION): ?string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse(trim($raw), $region);
        } catch (NumberParseException) {
            return null;
        }

        // Strict allocation checks reject Ofcom's reserved drama/test ranges
        // (07700 900xxx) and lag behind newly allocated ranges, so only the
        // full length and structure for the region are enforced.
        if ($util->isPossibleNumberWithReason($number) !== ValidationResult::IS_POSSIBLE) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }
}
