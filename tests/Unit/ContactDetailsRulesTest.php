<?php

namespace Tests\Unit;

use App\Rules\PhoneNumber;
use App\Rules\UkPostcode;
use App\Rules\WhatsAppContact;
use App\Support\Account\ContactDetails;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContactDetailsRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function postcodes(): array
    {
        return [
            'standard' => ['LS1 4AP', true],
            'no space' => ['SW1A1AA', true],
            'lower case' => ['ec1a 1bb', true],
            'GIR' => ['GIR 0AA', true],
            'numbers only' => ['12345', false],
            'too short' => ['LS1', false],
            'letters in inward digit' => ['LS1 AAP', false],
        ];
    }

    #[DataProvider('postcodes')]
    public function test_uk_postcode(string $value, bool $valid): void
    {
        $this->assertSame($valid, $this->passes(new UkPostcode, $value));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function phoneNumbers(): array
    {
        return [
            'UK mobile with spaces' => ['07700 900123', true],
            'international' => ['+44 7911 123456', true],
            'US number' => ['+1 415 555 2671', true],
            'too short' => ['0770', false],
            'too long' => ['077009001234567', false],
            'letters' => ['call me', false],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function test_phone_number(string $value, bool $valid): void
    {
        $this->assertSame($valid, $this->passes(new PhoneNumber, $value));
    }

    public function test_whatsapp_contact_checks_against_the_chosen_type(): void
    {
        $this->assertTrue($this->passes(new WhatsAppContact('number'), '07700 900123'));
        $this->assertFalse($this->passes(new WhatsAppContact('number'), 'apes_news'));
        $this->assertTrue($this->passes(new WhatsAppContact('username'), 'apes_news'));
        $this->assertTrue($this->passes(new WhatsAppContact('username'), '@apes.news'));
        $this->assertFalse($this->passes(new WhatsAppContact('username'), '12345'));
        $this->assertFalse($this->passes(new WhatsAppContact('username'), 'no spaces allowed'));
    }

    public function test_normalize_formats_mobile_postcode_and_country(): void
    {
        $out = ContactDetails::normalize([
            'address_line1' => '  10 Example Street ',
            'address_line2' => '',
            'address_postcode' => 'ls14ap',
            'address_country' => 'gb',
            'mobile_number' => '07700 900123',
        ]);

        $this->assertSame('10 Example Street', $out['address_line1']);
        $this->assertNull($out['address_line2']);
        $this->assertSame('LS1 4AP', $out['address_postcode']);
        $this->assertSame('GB', $out['address_country']);
        $this->assertSame('+447700900123', $out['mobile_number']);
        $this->assertNull($out['whatsapp_type']);
        $this->assertNull($out['whatsapp_value']);
        $this->assertFalse($out['whatsapp_same_as_mobile']);
    }

    public function test_normalize_defaults_country_and_keeps_invalid_input_for_validation(): void
    {
        $out = ContactDetails::normalize(['mobile_number' => 'not a number']);

        $this->assertSame('GB', $out['address_country']);
        $this->assertSame('not a number', $out['mobile_number']);
    }

    public function test_same_as_mobile_mirrors_the_mobile_and_ignores_the_typed_value(): void
    {
        $out = ContactDetails::normalize([
            'mobile_number' => '07700 900123',
            'whatsapp_same_as_mobile' => '1',
            'whatsapp_type' => 'username',
            'whatsapp_value' => 'ignored',
        ]);

        $this->assertTrue($out['whatsapp_same_as_mobile']);
        $this->assertSame('number', $out['whatsapp_type']);
        $this->assertSame('+447700900123', $out['whatsapp_value']);
    }

    public function test_whatsapp_username_drops_the_at_sign_and_type_clears_without_a_value(): void
    {
        $username = ContactDetails::normalize(['whatsapp_type' => 'username', 'whatsapp_value' => '@apes_news']);
        $this->assertSame('apes_news', $username['whatsapp_value']);

        $empty = ContactDetails::normalize(['whatsapp_type' => 'number', 'whatsapp_value' => '  ']);
        $this->assertNull($empty['whatsapp_type']);
        $this->assertNull($empty['whatsapp_value']);
    }

    private function passes(ValidationRule $rule, string $value): bool
    {
        $passed = true;
        $rule->validate('field', $value, function () use (&$passed) {
            $passed = false;
        });

        return $passed;
    }
}
