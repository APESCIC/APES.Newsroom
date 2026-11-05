<?php

namespace Tests\Integration;

use App\Services\Auth\LdapGroupLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalOpenLdapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_LIVE_LDAP_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_LIVE_LDAP_TESTS=1 to query the disposable local OpenLDAP fixture.');
        }
    }

    #[DataProvider('localRoleMappings')]
    public function test_seeded_user_resolves_to_expected_newsroom_group(
        string $email,
        string $expectedGroupDn,
    ): void {
        $groups = app(LdapGroupLookup::class)->groupsForEmail($email);

        $this->assertContains($expectedGroupDn, $groups);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function localRoleMappings(): array
    {
        return [
            'staff' => [
                'staffer@apes.local',
                'cn=newsroom-staff,ou=groups,dc=apes,dc=local',
            ],
            'admin' => [
                'admin@apes.local',
                'cn=newsroom-admins,ou=groups,dc=apes,dc=local',
            ],
        ];
    }
}
