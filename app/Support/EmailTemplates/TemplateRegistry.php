<?php

namespace App\Support\EmailTemplates;

use InvalidArgumentException;

class TemplateRegistry
{
    public const GROUP_ACCOUNT = 'account';

    public const GROUP_SECURITY = 'security';

    public const GROUP_MAILING_CONSENT = 'mailing_consent';

    public const GROUP_STAFF_ALERTS = 'staff_alerts';

    public const GROUP_TESTS = 'tests';

    private const SECURITY_LOCKED = 'The “Was this you?” advice and the security action button are applied by the application and cannot be edited. Do not put secrets, codes or sign-in links in the body.';

    /** @var array<string, TemplateDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return list<TemplateDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions());
    }

    public function get(string $key): TemplateDefinition
    {
        $definitions = $this->definitions();

        if (! isset($definitions[$key])) {
            throw new InvalidArgumentException("Unknown email template key [{$key}].");
        }

        return $definitions[$key];
    }

    /**
     * @return array<string, list<TemplateDefinition>>
     */
    public function groups(): array
    {
        $grouped = [];

        foreach ($this->all() as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array<string, TemplateDefinition>
     */
    private function definitions(): array
    {
        return self::$definitions ??= $this->build();
    }

    /**
     * @return array<string, TemplateDefinition>
     */
    private function build(): array
    {
        $items = [
            new TemplateDefinition(
                key: 'verify_email',
                name: 'Verify email address',
                group: self::GROUP_ACCOUNT,
                allowedPlaceholders: ['url'],
                requiredPlaceholders: ['url'],
                defaultSubject: 'Verify your email address',
                defaultBody: "Please click the button below to verify your email address.\n\nIf you did not create an account, no further action is required.",
                lockedNotice: 'Layout, footer and the verification action URL are fixed.',
                actionLabel: 'Verify Email Address',
                actionUrlPlaceholder: 'url',
                samplePlaceholders: ['url' => 'https://example.test/verify'],
            ),
            new TemplateDefinition(
                key: 'password_reset',
                name: 'Password reset',
                group: self::GROUP_ACCOUNT,
                allowedPlaceholders: ['url', 'expire_minutes'],
                requiredPlaceholders: ['url', 'expire_minutes'],
                defaultSubject: 'Reset your password',
                defaultBody: "You are receiving this email because we received a password reset request for your account.\n\nThis password reset link will expire in {{expire_minutes}} minutes.\n\nIf you did not request a password reset, no further action is required.",
                lockedNotice: 'Layout, footer and the reset action URL are fixed.',
                actionLabel: 'Reset Password',
                actionUrlPlaceholder: 'url',
                samplePlaceholders: [
                    'url' => 'https://example.test/reset',
                    'expire_minutes' => '60',
                ],
            ),
            new TemplateDefinition(
                key: 'magic_link',
                name: 'Magic sign-in link',
                group: self::GROUP_ACCOUNT,
                allowedPlaceholders: ['url'],
                requiredPlaceholders: ['url'],
                defaultSubject: 'Your APES Newsroom sign-in link',
                defaultBody: "Click the button below to sign in. This link expires in 15 minutes and can only be used once.\n\nIf you did not request this, you can safely ignore this email.",
                lockedNotice: 'Layout, footer and the single-use sign-in URL are fixed.',
                actionLabel: 'Sign in',
                actionUrlPlaceholder: 'url',
                samplePlaceholders: ['url' => 'https://example.test/magic'],
            ),
            new TemplateDefinition(
                key: 'reauthentication_code',
                name: 'Confirmation code',
                group: self::GROUP_ACCOUNT,
                allowedPlaceholders: ['code', 'minutes'],
                requiredPlaceholders: ['code', 'minutes'],
                defaultSubject: 'Your APES Newsroom confirmation code',
                defaultBody: "Use this code to confirm it is you before changing your account security settings:\n\n**{{code}}**\n\nThe code expires in {{minutes}} minutes. Never share it with anyone.\n\nIf you did not ask for this code, someone may be using your account. Sign out of other sessions and contact the newsroom team.",
                lockedNotice: 'Layout and footer are fixed. The runtime always injects the live code.',
                samplePlaceholders: ['code' => '482913', 'minutes' => '10'],
            ),
            new TemplateDefinition(
                key: 'confirm_email_change',
                name: 'Confirm new email',
                group: self::GROUP_ACCOUNT,
                allowedPlaceholders: ['url', 'minutes'],
                requiredPlaceholders: ['url', 'minutes'],
                defaultSubject: 'Confirm your new APES Newsroom email address',
                defaultBody: "Confirm this address to finish changing the email on your APES Newsroom account.\n\nThe link expires in {{minutes}} minutes.\n\nIf you did not ask for this, ignore this email; nothing will change.",
                lockedNotice: 'Layout, footer and the confirmation action URL are fixed.',
                actionLabel: 'Confirm new email address',
                actionUrlPlaceholder: 'url',
                samplePlaceholders: [
                    'url' => 'https://example.test/confirm-email',
                    'minutes' => '60',
                ],
            ),
            new TemplateDefinition(
                key: 'account_locked',
                name: 'Account locked',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: your account has been locked',
                defaultBody: "An administrator has locked your APES Newsroom account, usually to protect it after suspicious activity.\n\nYou have been signed out everywhere and cannot sign in until the account is unlocked.\n\nIf you were not expecting this, please contact the newsroom team.",
                isSecurity: true,
                lockedNotice: 'No “Was this you?” block or security CTA — the account cannot sign in. Layout and footer are fixed.',
            ),
            new TemplateDefinition(
                key: 'account_unlocked',
                name: 'Account unlocked',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: your account has been unlocked',
                defaultBody: 'An administrator has unlocked your APES Newsroom account. You can sign in again.',
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'admin_signed_out',
                name: 'Admin signed you out',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: you were signed out by an administrator',
                defaultBody: "An administrator signed your APES Newsroom account out of every device to protect it.\n\nYou can sign in again as normal.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'admin_password_reset',
                name: 'Admin reset password',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: an administrator reset your password',
                defaultBody: "An administrator reset the password on your APES Newsroom account to protect it, and signed you out everywhere.\n\nWe have sent a separate email with a link to choose a new password.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'admin_two_factor_reset',
                name: 'Admin reset 2FA',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: an administrator turned off two-factor sign-in',
                defaultBody: "An administrator turned off two-factor sign-in (authenticator app and recovery codes) on your APES Newsroom account.\n\nPlease set it up again from your account security page.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'admin_passkeys_removed',
                name: 'Admin removed passkeys',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: an administrator removed your passkeys',
                defaultBody: "An administrator removed every passkey from your APES Newsroom account.\n\nYou can add a new passkey from your account security page.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'api_token_created',
                name: 'API token created',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: ['token_name'],
                requiredPlaceholders: ['token_name'],
                defaultSubject: 'APES Newsroom: a new Admin API token was created',
                defaultBody: "An Admin API token named \"{{token_name}}\" was created for your account.\n\nTokens act with your permissions. The token itself is never sent by email.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
                samplePlaceholders: ['token_name' => 'Adoption feed'],
            ),
            new TemplateDefinition(
                key: 'email_change_requested',
                name: 'Email change requested',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: ['masked_new_email', 'cancel_url'],
                requiredPlaceholders: ['masked_new_email', 'cancel_url'],
                defaultSubject: 'APES Newsroom: email address change requested',
                defaultBody: "Someone asked to change the email address on your APES Newsroom account to {{masked_new_email}}.\n\nYour email address stays the same until the new address is confirmed.",
                isSecurity: true,
                lockedNotice: 'The cancel action URL is fixed. Never put the raw new email unmasked. '.self::SECURITY_LOCKED,
                actionLabel: 'Cancel this change',
                actionUrlPlaceholder: 'cancel_url',
                samplePlaceholders: [
                    'masked_new_email' => 'b***@example.org',
                    'cancel_url' => 'https://example.test/cancel-email-change',
                ],
            ),
            new TemplateDefinition(
                key: 'new_device_sign_in',
                name: 'New device sign-in',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: ['device', 'masked_ip', 'signed_in_at'],
                requiredPlaceholders: ['device', 'signed_in_at'],
                defaultSubject: 'APES Newsroom: new sign-in to your account',
                defaultBody: "Your APES Newsroom account was signed in to from a device we have not seen recently.\n\nDevice: {{device}}\n\nNetwork: {{masked_ip}}\n\nTime: {{signed_in_at}}",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED.' The Network line may be omitted at send time when no IP is available.',
                actionLabel: 'Review account security',
                samplePlaceholders: [
                    'device' => 'Chrome on Windows',
                    'masked_ip' => '81.2.144.x',
                    'signed_in_at' => '30 Oct 2026 03:00',
                ],
            ),
            new TemplateDefinition(
                key: 'passkey_added',
                name: 'Passkey added',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: ['passkey_name'],
                requiredPlaceholders: ['passkey_name'],
                defaultSubject: 'APES Newsroom: passkey added',
                defaultBody: "A passkey named \"{{passkey_name}}\" was added to your APES Newsroom account.\n\nIt can now be used to sign in.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
                samplePlaceholders: ['passkey_name' => 'iPhone'],
            ),
            new TemplateDefinition(
                key: 'passkey_removed',
                name: 'Passkey removed',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: ['passkey_name'],
                requiredPlaceholders: ['passkey_name'],
                defaultSubject: 'APES Newsroom: passkey removed',
                defaultBody: "The passkey named \"{{passkey_name}}\" was removed from your APES Newsroom account.\n\nIt can no longer be used to sign in.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
                samplePlaceholders: ['passkey_name' => 'YubiKey'],
            ),
            new TemplateDefinition(
                key: 'password_changed',
                name: 'Password changed',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: your password was changed',
                defaultBody: "The password for your APES Newsroom account was changed.\n\nOther signed-in sessions have been signed out.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED.' When the change came from a reset link, defaultBodyViaReset is used for the default preview only.',
                actionLabel: 'Review account security',
                defaultBodyViaReset: "The password for your APES Newsroom account was reset using a reset link.\n\nOther signed-in sessions have been signed out.",
            ),
            new TemplateDefinition(
                key: 'recovery_codes_regenerated',
                name: 'Recovery codes regenerated',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: new recovery codes generated',
                defaultBody: "New two-factor recovery codes were generated for your APES Newsroom account.\n\nYour previous recovery codes no longer work. The codes are never sent by email.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'two_factor_enabled',
                name: '2FA enabled',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: two-factor authentication turned on',
                defaultBody: "Two-factor authentication was turned on for your APES Newsroom account.\n\nYou will be asked for a code from your authenticator app when you sign in.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'two_factor_disabled',
                name: '2FA disabled',
                group: self::GROUP_SECURITY,
                allowedPlaceholders: [],
                requiredPlaceholders: [],
                defaultSubject: 'APES Newsroom: two-factor authentication turned off',
                defaultBody: "Two-factor authentication was turned off for your APES Newsroom account.\n\nSigning in now needs only your password or an emailed link.",
                isSecurity: true,
                lockedNotice: self::SECURITY_LOCKED,
                actionLabel: 'Review account security',
            ),
            new TemplateDefinition(
                key: 'confirm_mailing_list',
                name: 'Confirm mailing list',
                group: self::GROUP_MAILING_CONSENT,
                allowedPlaceholders: ['list_label', 'list_purpose', 'confirm_url'],
                requiredPlaceholders: ['list_label', 'confirm_url'],
                defaultSubject: 'Confirm your {{list_label}} subscription',
                defaultBody: "You asked to join the {{list_label}} mailing list.\n\n{{list_purpose}}\n\nIf you did not request this, you can ignore this email.",
                lockedNotice: 'From/Reply-To come from the verified newsletter identity. The confirm action URL is fixed.',
                actionLabel: 'Confirm subscription',
                actionUrlPlaceholder: 'confirm_url',
                samplePlaceholders: [
                    'list_label' => 'APES Shelter & Rescue',
                    'list_purpose' => 'Adoption news and shelter updates.',
                    'confirm_url' => 'https://example.test/confirm-list',
                ],
            ),
            new TemplateDefinition(
                key: 'confirm_newsletter',
                name: 'Confirm newsletter',
                group: self::GROUP_MAILING_CONSENT,
                allowedPlaceholders: ['newsletter_name', 'newsletter_description', 'confirm_url'],
                requiredPlaceholders: ['newsletter_name', 'confirm_url'],
                defaultSubject: 'Confirm your {{newsletter_name}} subscription',
                defaultBody: "You asked to join the {{newsletter_name}} newsletter.\n\n{{newsletter_description}}\n\nIf you did not request this, you can ignore this email.",
                lockedNotice: 'From/Reply-To come from the verified newsletter identity. The confirm action URL is fixed. Description may be blank at send time.',
                actionLabel: 'Confirm subscription',
                actionUrlPlaceholder: 'confirm_url',
                samplePlaceholders: [
                    'newsletter_name' => 'APES Shelter & Rescue',
                    'newsletter_description' => 'Adoption news.',
                    'confirm_url' => 'https://example.test/confirm-newsletter',
                ],
            ),
            new TemplateDefinition(
                key: 'campaign_paused',
                name: 'Campaign paused',
                group: self::GROUP_STAFF_ALERTS,
                allowedPlaceholders: ['title', 'reason', 'remaining', 'campaign_url'],
                requiredPlaceholders: ['title', 'reason', 'remaining'],
                defaultSubject: 'APES Newsroom: newsletter send paused ({{title}})',
                defaultBody: "Sending \"{{title}}\" has paused because the newsletter mail transport failed. {{remaining}} recipients are still waiting.\n\n{{reason}}\n\nNothing switches to another transport. Fix the problem under Admin → Settings → Mail sending, then resume the send from the campaign page.",
                lockedNotice: 'Layout and footer are fixed. The action targets the campaign page or mail-sending settings.',
                actionLabel: 'Open the campaign',
                actionUrlPlaceholder: 'campaign_url',
                samplePlaceholders: [
                    'title' => 'Rescued dragons',
                    'reason' => 'SMTP timeout',
                    'remaining' => '12',
                    'campaign_url' => 'https://example.test/campaign',
                ],
            ),
            new TemplateDefinition(
                key: 'integration_check_failed',
                name: 'Integration check failed',
                group: self::GROUP_STAFF_ALERTS,
                allowedPlaceholders: ['provider_label', 'message', 'checked_at'],
                requiredPlaceholders: ['provider_label', 'message', 'checked_at'],
                defaultSubject: 'APES Newsroom: {{provider_label}} integration check is failing',
                defaultBody: "The {{provider_label}} integration check started failing at {{checked_at}}.\n\n{{message}}\n\nFeatures that depend on it may not work until it is fixed. You will not get another email while it keeps failing.",
                lockedNotice: 'Layout and footer are fixed. The message must stay free of key material.',
                actionLabel: 'Review integrations',
                samplePlaceholders: [
                    'provider_label' => 'Mailgun',
                    'message' => 'HTTP 401 Unauthorized',
                    'checked_at' => '2026-10-30 03:00',
                ],
            ),
            new TemplateDefinition(
                key: 'sending_domain_test',
                name: 'Sending domain test',
                group: self::GROUP_TESTS,
                allowedPlaceholders: ['from_address', 'mailgun_domain', 'requested_by', 'sent_at'],
                requiredPlaceholders: ['from_address', 'mailgun_domain', 'requested_by', 'sent_at'],
                defaultSubject: '[TEST] Sending check for {{from_address}}',
                defaultBody: "**TEST EMAIL** — sent from Admin → Settings → Email sending domains.\n\nThis message was sent from **{{from_address}}** through the Mailgun domain **{{mailgun_domain}}** at {{sent_at}}, at the request of {{requested_by}}.\n\nIf it reached your inbox, the APES Newsroom can send from this address. To confirm SPF, DKIM and DMARC alignment, open the message headers and look for `dmarc=pass` in `Authentication-Results`.\n\nNo action is needed. You can delete this email.",
                lockedNotice: 'From address and Mailgun domain are operational values, not free-form branding.',
                samplePlaceholders: [
                    'from_address' => 'news@shelter.apesnews.org.uk',
                    'mailgun_domain' => 'shelter.apesnews.org.uk',
                    'requested_by' => 'Staff Demo',
                    'sent_at' => '6 Nov 2026, 12:00',
                ],
            ),
            new TemplateDefinition(
                key: 'transport_test',
                name: 'Transport test',
                group: self::GROUP_TESTS,
                allowedPlaceholders: ['purpose_label', 'from_address', 'transport_label', 'requested_by', 'sent_at'],
                requiredPlaceholders: ['purpose_label', 'from_address', 'transport_label', 'requested_by', 'sent_at'],
                defaultSubject: '[TEST] {{purpose_label}} through {{transport_label}}',
                defaultBody: "**TEST EMAIL** — sent from Admin → Settings → Mail sending.\n\nThis message was sent as **{{purpose_label}}** from **{{from_address}}** through **{{transport_label}}** at {{sent_at}}, at the request of {{requested_by}}.\n\nIf it reached your inbox, this transport can send {{purpose_label}}. To confirm SPF, DKIM and DMARC alignment, open the message headers and look for `dmarc=pass` in `Authentication-Results`.\n\nNo action is needed. You can delete this email.",
                lockedNotice: 'Purpose and transport labels come from settings enums.',
                samplePlaceholders: [
                    'purpose_label' => 'Newsletters',
                    'from_address' => 'newsroom@apesnews.org.uk',
                    'transport_label' => 'Mailgun API',
                    'requested_by' => 'Staff Demo',
                    'sent_at' => '6 Nov 2026, 12:00',
                ],
            ),
        ];

        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item->key] = $item;
        }

        return $keyed;
    }
}
