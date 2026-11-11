<?php

namespace App\Services\Geo;

interface GeoLocator
{
    /**
     * The approximate location of a public IP address, or null when it
     * cannot be resolved. Implementations must never call a remote API.
     */
    public function locate(?string $ip): ?GeoLocation;
}
