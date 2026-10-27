<?php

namespace App\Support\Account;

use App\Models\User;
use App\Rules\PhoneNumber;
use App\Rules\UkPostcode;
use App\Rules\WhatsAppContact;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Shared normalisation and validation for the optional private contact
 * fields, used by both the account settings form and the admin user editor.
 */
final class ContactDetails
{
    /**
     * @var list<string>
     */
    public const WHATSAPP_TYPES = ['number', 'username'];

    /**
     * Groups used in audit payloads, so values never leave the users table.
     *
     * @var array<string, list<string>>
     */
    private const AUDIT_GROUPS = [
        'address' => ['address_line1', 'address_line2', 'address_town', 'address_county', 'address_postcode', 'address_country'],
        'mobile_number' => ['mobile_number'],
        'whatsapp' => ['whatsapp_type', 'whatsapp_value', 'whatsapp_same_as_mobile'],
    ];

    /**
     * Run before validation: trims, upper-cases the postcode and country,
     * converts valid phone numbers to E.164 and resolves "same as mobile".
     * Invalid values are left as typed so validation can reject them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $out = [];

        foreach (['address_line1', 'address_line2', 'address_town', 'address_county'] as $field) {
            $out[$field] = self::clean($input[$field] ?? null);
        }

        $country = self::clean($input['address_country'] ?? null);
        $out['address_country'] = $country === null ? 'GB' : Str::upper($country);

        $postcode = self::clean($input['address_postcode'] ?? null);
        if ($postcode !== null) {
            $postcode = Str::upper((string) preg_replace('/\s+/', ' ', $postcode));
            if ($out['address_country'] === 'GB' && preg_match(UkPostcode::PATTERN, $postcode) === 1) {
                $compact = str_replace(' ', '', $postcode);
                $postcode = substr($compact, 0, -3).' '.substr($compact, -3);
            }
        }
        $out['address_postcode'] = $postcode;

        $mobile = self::clean($input['mobile_number'] ?? null);
        $out['mobile_number'] = $mobile === null ? null : (PhoneNumbers::toE164($mobile) ?? $mobile);

        $sameAsMobile = filter_var($input['whatsapp_same_as_mobile'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $out['whatsapp_same_as_mobile'] = $sameAsMobile;

        if ($sameAsMobile) {
            $out['whatsapp_type'] = 'number';
            $out['whatsapp_value'] = $out['mobile_number'];
        } else {
            $type = self::clean($input['whatsapp_type'] ?? null);
            $value = self::clean($input['whatsapp_value'] ?? null);

            if ($value !== null && $type === 'number') {
                $value = PhoneNumbers::toE164($value) ?? $value;
            } elseif ($value !== null && $type === 'username') {
                $value = ltrim($value, '@');
            }

            $out['whatsapp_type'] = $value === null ? null : $type;
            $out['whatsapp_value'] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input  Normalised input.
     * @return array<string, mixed>
     */
    public static function rules(array $input): array
    {
        $isGb = ($input['address_country'] ?? 'GB') === 'GB';
        $sameAsMobile = (bool) ($input['whatsapp_same_as_mobile'] ?? false);

        return [
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'address_town' => ['nullable', 'string', 'max:100'],
            'address_county' => ['nullable', 'string', 'max:100'],
            'address_postcode' => ['nullable', 'string', 'max:16', ...($isGb ? [new UkPostcode] : [])],
            'address_country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'mobile_number' => [
                Rule::requiredIf($sameAsMobile),
                'nullable',
                'string',
                'max:32',
                new PhoneNumber,
            ],
            'whatsapp_same_as_mobile' => ['boolean'],
            'whatsapp_type' => ['nullable', 'required_with:whatsapp_value', Rule::in(self::WHATSAPP_TYPES)],
            'whatsapp_value' => [
                'nullable',
                'string',
                'max:64',
                new WhatsAppContact($input['whatsapp_type'] ?? null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'address_country.regex' => 'Choose a country using its two-letter code, for example GB.',
            'mobile_number.required' => 'Add a mobile number, or untick "same as mobile" for WhatsApp.',
            'whatsapp_type.required_with' => 'Choose whether your WhatsApp contact is a number or a username.',
        ];
    }

    /**
     * Field groups whose values differ. Values themselves are never returned.
     *
     * @param  array<string, mixed>  $after  Validated, normalised values.
     * @return list<string>
     */
    public static function changedGroups(User $user, array $after): array
    {
        $before = $user->contactDetails();
        $changed = [];

        foreach (self::AUDIT_GROUPS as $group => $fields) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $after) && $after[$field] !== $before[$field]) {
                    $changed[] = $group;
                    break;
                }
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function only(array $validated): array
    {
        return array_intersect_key($validated, array_flip(User::CONTACT_FIELDS));
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
