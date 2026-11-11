<?php

namespace Tests\Unit\Geo;

use App\Services\Geo\DbIpGeoLocator;
use App\Services\Geo\GeoLocation;
use Tests\TestCase;

class DbIpGeoLocatorTest extends TestCase
{
    public function test_a_missing_database_file_resolves_nothing(): void
    {
        $locator = new DbIpGeoLocator(sys_get_temp_dir().'/missing-'.uniqid().'.mmdb');

        $this->assertNull($locator->locate('81.2.69.160'));
    }

    public function test_an_unreadable_database_file_resolves_nothing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geoip');
        file_put_contents($path, 'not an mmdb file');

        try {
            $this->assertNull((new DbIpGeoLocator($path))->locate('81.2.69.160'));
        } finally {
            unlink($path);
        }
    }

    public function test_private_reserved_and_invalid_addresses_are_never_looked_up(): void
    {
        $locator = new DbIpGeoLocator(sys_get_temp_dir().'/missing-'.uniqid().'.mmdb');

        foreach ([null, '', 'not-an-ip', '127.0.0.1', '10.0.0.4', '192.168.1.20', '::1', 'fd00::1'] as $ip) {
            $this->assertNull($locator->locate($ip));
        }
    }

    public function test_locations_are_described_in_plain_english(): void
    {
        $this->assertSame('Leeds, United Kingdom', GeoLocation::describe('GB', 'Leeds'));
        $this->assertSame('Ireland', GeoLocation::describe('IE', null));
        $this->assertNull(GeoLocation::describe(null, 'Leeds'));
    }
}
