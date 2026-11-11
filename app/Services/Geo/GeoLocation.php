<?php

namespace App\Services\Geo;

use Locale;

/**
 * An approximate, city-level location. Coordinates are never kept.
 */
final readonly class GeoLocation
{
    public function __construct(
        public string $countryCode,
        public ?string $city = null,
    ) {}

    /**
     * "Leeds, United Kingdom", or just the country when the city is unknown.
     */
    public static function describe(?string $countryCode, ?string $city): ?string
    {
        if ($countryCode === null || $countryCode === '') {
            return null;
        }

        $country = Locale::getDisplayRegion('-'.$countryCode, 'en');
        if ($country === '' || $country === $countryCode) {
            $country = strtoupper($countryCode);
        }

        return $city !== null && $city !== '' ? "{$city}, {$country}" : $country;
    }
}
