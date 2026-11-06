<?php

namespace Tests\Unit\EmailTemplates;

use App\Support\EmailTemplates\TemplateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateRegistryTest extends TestCase
{
    #[Test]
    public function it_lists_all_twenty_six_system_template_keys(): void
    {
        $registry = new TemplateRegistry;
        $keys = $registry->keys();

        // Compact inventory table is 26 keys (5 account + 15 security + 2 consent + 2 staff + 2 tests).
        $this->assertCount(26, $keys);
        $this->assertCount(26, $registry->all());
        $this->assertContains('magic_link', $keys);
        $this->assertContains('admin_signed_out', $keys);
        $this->assertContains('admin_password_reset', $keys);
        $this->assertContains('admin_two_factor_reset', $keys);
        $this->assertContains('admin_passkeys_removed', $keys);
        $this->assertContains('password_changed', $keys);
        $this->assertContains('sending_domain_test', $keys);
        $this->assertNotNull($registry->get('password_changed')->defaultBodyViaReset);
    }

    #[Test]
    public function it_groups_definitions_and_rejects_unknown_keys(): void
    {
        $registry = new TemplateRegistry;
        $groups = $registry->groups();

        $this->assertArrayHasKey(TemplateRegistry::GROUP_ACCOUNT, $groups);
        $this->assertArrayHasKey(TemplateRegistry::GROUP_SECURITY, $groups);
        $this->assertArrayHasKey(TemplateRegistry::GROUP_MAILING_CONSENT, $groups);
        $this->assertArrayHasKey(TemplateRegistry::GROUP_STAFF_ALERTS, $groups);
        $this->assertArrayHasKey(TemplateRegistry::GROUP_TESTS, $groups);
        $this->assertCount(5, $groups[TemplateRegistry::GROUP_ACCOUNT]);
        $this->assertCount(15, $groups[TemplateRegistry::GROUP_SECURITY]);

        $this->expectException(InvalidArgumentException::class);
        $registry->get('not_a_real_key');
    }
}
