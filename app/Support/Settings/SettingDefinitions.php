<?php

namespace App\Support\Settings;

use App\Enums\Role;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Single source of truth for admin-managed site settings.
 *
 * Every key the SettingsRepository reads or writes must be declared here with
 * its type, default and presentation metadata for the admin Settings page.
 *
 * @phpstan-type Definition array{
 *     section: string,
 *     page?: string,
 *     label: string,
 *     help: string,
 *     type: 'enum'|'int'|'bool'|'multi_enum'|'string',
 *     default: mixed,
 *     options?: array<string, string>,
 *     min?: int,
 *     max?: int,
 *     unit?: string,
 *     rows?: int,
 * }
 */
final class SettingDefinitions
{
    public const EDITOR_DRIVER = 'editor.driver';

    public const EDITOR_DRIVERS = ['editorjs', 'tinymce'];

    public const PASSWORD_MIN_LENGTH = 'security.password.min_length';

    public const PASSWORD_CHECK_BREACHED = 'security.password.check_breached';

    public const SESSION_LIFETIME = 'security.session.lifetime';

    public const MAGIC_LINKS_ENABLED = 'security.magic_links.enabled';

    public const TWO_FACTOR_REQUIRED_ROLES = 'security.two_factor.required_roles';

    public const MAIL_SYSTEM_TRANSPORT = 'mail.system_transport';

    public const MAIL_NEWSLETTER_TRANSPORT = 'mail.newsletter_transport';

    public const MAIL_SYSTEM_FALLBACK = 'mail.system_fallback';

    public const MAIL_SOFT_BOUNCE_LIMIT = 'mail.soft_bounce.limit';

    public const MAIL_SOFT_BOUNCE_WINDOW = 'mail.soft_bounce.window_days';

    public const SEO_DEFAULT_DESCRIPTION = 'seo.default_description';

    public const SEO_DEFAULT_SHARE_IMAGE = 'seo.default_share_image';

    public const SEO_TWITTER_HANDLE = 'seo.twitter_handle';

    public const SEO_FACEBOOK_URL = 'seo.facebook_url';

    public const SEO_LINKEDIN_URL = 'seo.linkedin_url';

    public const SEO_YOUTUBE_URL = 'seo.youtube_url';

    public const SEO_INSTAGRAM_URL = 'seo.instagram_url';

    public const SEO_GOOGLE_SITE_VERIFICATION = 'seo.google_site_verification';

    public const SEO_BING_SITE_VERIFICATION = 'seo.bing_site_verification';

    public const SEO_NOINDEX_TAG_PAGES = 'seo.noindex_tag_pages';

    public const SEO_NOINDEX_AUTHOR_PAGES = 'seo.noindex_author_pages';

    public const SEO_ROBOTS_TXT = 'seo.robots_txt';

    public const SEO_TITLE_PATTERN = 'seo.title_pattern';

    /** Settings shown on Admin → Settings → Mail sending, changed by admins after a recent sign-in. */
    public const PAGE_MAIL_SENDING = 'mail-sending';

    /** Passwords shorter than this are never allowed, whatever the setting says. */
    public const PASSWORD_MIN_LENGTH_FLOOR = 12;

    /**
     * @return array<string, string>
     */
    public static function transportOptions(): array
    {
        return [
            'cloudron_smtp' => 'Cloudron SMTP',
            'mailgun_api' => 'Mailgun API',
            'custom_smtp' => 'Custom SMTP',
        ];
    }

    /**
     * Definitions for one settings page; null is the main Settings page.
     *
     * @return array<string, Definition>
     */
    public static function forPage(?string $page): array
    {
        return array_filter(self::all(), fn (array $definition) => ($definition['page'] ?? null) === $page);
    }

    /**
     * @return array<string, Definition>
     */
    public static function all(): array
    {
        return [
            self::EDITOR_DRIVER => [
                'section' => 'Editor',
                'label' => 'Staff editor',
                'help' => 'Editor on staff post and page screens. Editor.js has every block tool, including callouts, toggles, galleries, video, audio, files, bookmarks, products and embeds. TinyMCE offers familiar word-processor editing; those blocks show there as read-only grey boxes that are edited in Editor.js. Content is always stored as Editor.js blocks, so you can switch at any time: staff get the new editor the next time they open a post or page.',
                'type' => 'enum',
                'default' => 'editorjs',
                'options' => [
                    'editorjs' => 'Editor.js',
                    'tinymce' => 'TinyMCE',
                ],
            ],
            self::PASSWORD_MIN_LENGTH => [
                'section' => 'Security',
                'label' => 'Minimum password length',
                'help' => 'Applies to new passwords at registration, reset and change. Existing passwords keep working.',
                'type' => 'int',
                'default' => max(self::PASSWORD_MIN_LENGTH_FLOOR, (int) config('security.password.min_length', 12)),
                'min' => self::PASSWORD_MIN_LENGTH_FLOOR,
                'max' => 128,
                'unit' => 'characters',
            ],
            self::PASSWORD_CHECK_BREACHED => [
                'section' => 'Security',
                'label' => 'Refuse breached passwords',
                'help' => 'Checks new passwords against the Have I Been Pwned range API without sending the password. If the service is unreachable the password is accepted.',
                'type' => 'bool',
                'default' => (bool) config('security.password.check_breached', false),
            ],
            self::SESSION_LIFETIME => [
                'section' => 'Security',
                'label' => 'Idle session lifetime',
                'help' => 'Signed-in sessions end after this many minutes without activity. "Keep me signed in" devices are not affected.',
                'type' => 'int',
                'default' => (int) config('session.lifetime', 120),
                'min' => 15,
                'max' => 1440,
                'unit' => 'minutes',
            ],
            self::MAGIC_LINKS_ENABLED => [
                'section' => 'Security',
                'label' => 'Allow email sign-in links',
                'help' => 'When off, members can only sign in with a password or passkey, and the "Email me a sign-in link" option is hidden.',
                'type' => 'bool',
                'default' => true,
            ],
            self::TWO_FACTOR_REQUIRED_ROLES => [
                'section' => 'Security',
                'label' => 'Roles that must use two-factor sign-in',
                'help' => 'Members in these roles must set up an authenticator app or passkey before they can use the site. Cloudron SSO accounts are exempt because Cloudron enforces its own multi-factor sign-in.',
                'type' => 'multi_enum',
                'default' => [],
                'options' => collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => ucwords(str_replace('_', ' ', $role->value))])->all(),
            ],
            self::MAIL_SYSTEM_TRANSPORT => [
                'section' => 'Mail sending',
                'page' => self::PAGE_MAIL_SENDING,
                'label' => 'System and account mail',
                'help' => 'Sign-in links, password resets, receipts and admin alerts.',
                'type' => 'enum',
                'default' => 'cloudron_smtp',
                'options' => self::transportOptions(),
            ],
            self::MAIL_NEWSLETTER_TRANSPORT => [
                'section' => 'Mail sending',
                'page' => self::PAGE_MAIL_SENDING,
                'label' => 'Newsletters',
                'help' => 'Campaign emails and newsletter confirmation emails. Newsletters never switch transport: if this one fails, the send pauses and admins are emailed.',
                'type' => 'enum',
                'default' => config('mailing.newsletter_transport') === 'smtp' ? 'cloudron_smtp' : 'mailgun_api',
                'options' => self::transportOptions(),
            ],
            self::MAIL_SYSTEM_FALLBACK => [
                'section' => 'Mail sending',
                'page' => self::PAGE_MAIL_SENDING,
                'label' => 'Fallback for system mail',
                'help' => 'Tried when the system mail transport fails. Newsletters never use a fallback.',
                'type' => 'enum',
                'default' => 'none',
                'options' => ['none' => 'No fallback', ...self::transportOptions()],
            ],
            self::MAIL_SOFT_BOUNCE_LIMIT => [
                'section' => 'Mail sending',
                'page' => self::PAGE_MAIL_SENDING,
                'label' => 'Temporary failures before an address is blocked',
                'help' => 'Counted on separate sends within the window below. A successful delivery resets the count.',
                'type' => 'int',
                'default' => 3,
                'min' => 2,
                'max' => 10,
                'unit' => 'failures',
            ],
            self::MAIL_SOFT_BOUNCE_WINDOW => [
                'section' => 'Mail sending',
                'page' => self::PAGE_MAIL_SENDING,
                'label' => 'Temporary failure window',
                'help' => 'Only temporary failures in this many days count towards blocking an address.',
                'type' => 'int',
                'default' => 30,
                'min' => 7,
                'max' => 90,
                'unit' => 'days',
            ],
            self::SEO_TITLE_PATTERN => [
                'section' => 'SEO',
                'label' => 'Title pattern',
                'help' => 'How automatic titles are finished. Use {title} for the page title. Default appends “ | APES Newsroom”.',
                'type' => 'string',
                'default' => '{title} | APES Newsroom',
                'max' => 120,
            ],
            self::SEO_DEFAULT_DESCRIPTION => [
                'section' => 'SEO',
                'label' => 'Default meta description',
                'help' => 'Used on the home page and anywhere a page has no description of its own.',
                'type' => 'string',
                'default' => 'News and updates from APES — Association of Pet Enthusiasts and Sanctuaries.',
                'max' => 300,
                'rows' => 3,
            ],
            self::SEO_DEFAULT_SHARE_IMAGE => [
                'section' => 'SEO',
                'label' => 'Default share image URL',
                'help' => 'Absolute or site-relative URL for Open Graph and X cards when a page has no hero. Leave blank to use the branded 1200×630 fallback.',
                'type' => 'string',
                'default' => '',
                'max' => 500,
            ],
            self::SEO_TWITTER_HANDLE => [
                'section' => 'SEO',
                'label' => 'X (Twitter) handle',
                'help' => 'Site handle without or with @, used as twitter:site and in Organization sameAs.',
                'type' => 'string',
                'default' => '',
                'max' => 64,
            ],
            self::SEO_FACEBOOK_URL => [
                'section' => 'SEO',
                'label' => 'Facebook profile URL',
                'help' => 'Public Facebook page URL for structured data.',
                'type' => 'string',
                'default' => '',
                'max' => 500,
            ],
            self::SEO_LINKEDIN_URL => [
                'section' => 'SEO',
                'label' => 'LinkedIn profile URL',
                'help' => 'Public LinkedIn page URL for structured data.',
                'type' => 'string',
                'default' => '',
                'max' => 500,
            ],
            self::SEO_YOUTUBE_URL => [
                'section' => 'SEO',
                'label' => 'YouTube channel URL',
                'help' => 'Public YouTube channel URL for structured data.',
                'type' => 'string',
                'default' => '',
                'max' => 500,
            ],
            self::SEO_INSTAGRAM_URL => [
                'section' => 'SEO',
                'label' => 'Instagram profile URL',
                'help' => 'Public Instagram profile URL for structured data.',
                'type' => 'string',
                'default' => '',
                'max' => 500,
            ],
            self::SEO_GOOGLE_SITE_VERIFICATION => [
                'section' => 'SEO',
                'label' => 'Google site verification',
                'help' => 'Content value for the google-site-verification meta tag.',
                'type' => 'string',
                'default' => '',
                'max' => 255,
            ],
            self::SEO_BING_SITE_VERIFICATION => [
                'section' => 'SEO',
                'label' => 'Bing site verification',
                'help' => 'Content value for the msvalidate.01 meta tag.',
                'type' => 'string',
                'default' => '',
                'max' => 255,
            ],
            self::SEO_NOINDEX_TAG_PAGES => [
                'section' => 'SEO',
                'label' => 'Noindex tag archive pages',
                'help' => 'When on, public tag listing pages send robots noindex,follow.',
                'type' => 'bool',
                'default' => false,
            ],
            self::SEO_NOINDEX_AUTHOR_PAGES => [
                'section' => 'SEO',
                'label' => 'Noindex author archive pages',
                'help' => 'When on, public author listing pages send robots noindex,follow.',
                'type' => 'bool',
                'default' => false,
            ],
            self::SEO_ROBOTS_TXT => [
                'section' => 'SEO',
                'label' => 'robots.txt',
                'help' => 'Full robots.txt body. A Sitemap line pointing at /sitemap.xml is added automatically if missing.',
                'type' => 'string',
                'default' => "User-agent: *\nDisallow:",
                'max' => 5000,
                'rows' => 8,
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return Definition
     */
    public static function get(string $key): array
    {
        $definitions = self::all();

        if (! array_key_exists($key, $definitions)) {
            throw new InvalidArgumentException("Undeclared setting [{$key}].");
        }

        return $definitions[$key];
    }

    public static function accepts(string $key, mixed $value): bool
    {
        $definition = self::get($key);

        return match ($definition['type']) {
            'enum' => is_string($value) && array_key_exists($value, $definition['options']),
            'int' => is_int($value) && $value >= $definition['min'] && $value <= $definition['max'],
            'bool' => is_bool($value),
            'multi_enum' => is_array($value) && array_is_list($value)
                && count($value) === count(array_unique($value))
                && collect($value)->every(fn ($item) => is_string($item) && array_key_exists($item, $definition['options'])),
            'string' => is_string($value) && mb_strlen($value) <= ($definition['max'] ?? 255),
        };
    }

    /**
     * Validation rules for the setting's value, keyed by path relative to
     * the setting ('' is the value itself, '.*' each item of a list).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $key): array
    {
        $definition = self::get($key);

        return match ($definition['type']) {
            'enum' => ['' => ['sometimes', 'required', 'string', Rule::in(array_keys($definition['options']))]],
            'int' => ['' => ['sometimes', 'required', 'integer', 'min:'.$definition['min'], 'max:'.$definition['max']]],
            'bool' => ['' => ['sometimes', 'required', 'boolean']],
            'multi_enum' => [
                '' => ['sometimes', 'array'],
                '.*' => ['string', 'distinct', Rule::in(array_keys($definition['options']))],
            ],
            'string' => ['' => ['sometimes', 'nullable', 'string', 'max:'.($definition['max'] ?? 255)]],
        };
    }

    /**
     * Converts a validated request value to the stored type.
     */
    public static function cast(string $key, mixed $value): mixed
    {
        return match (self::get($key)['type']) {
            'enum' => (string) $value,
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'multi_enum' => array_values(array_map('strval', (array) $value)),
            'string' => (string) ($value ?? ''),
        };
    }
}
