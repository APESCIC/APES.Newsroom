<?php

namespace App\Support\EmailTemplates;

/**
 * Built-in wording for the newsletter (campaign) email. These values only
 * prefill the editor: with no saved template the campaign email keeps its
 * original Blade rendering, so the default stays identical to v1.29.0.
 */
final class NewsletterTemplateDefaults
{
    public const KEY = 'newsletter_post';

    /** Scope of the one default newsletter template. */
    public const SCOPE_DEFAULT = 'newsletter';

    public const MEMBERS_CTA = 'This article is for members. Create a free account to read the full post.';

    public const PAID_CTA = 'This article is for paid members. Subscribe in your account to read the full post.';

    public const PLACEHOLDERS = [
        'post_title',
        'post_excerpt',
        'post_author',
        'read_more_url',
        'recipient_first_name',
        'newsletter_name',
        'channel_label',
        'site_name',
    ];

    /** Fields rendered once per campaign, before any recipient is known. */
    public const POST_PLACEHOLDERS = [
        'post_title',
        'post_excerpt',
        'post_author',
        'read_more_url',
        'newsletter_name',
        'channel_label',
        'site_name',
    ];

    public const TEXT_SETTINGS = ['sign_off', 'read_more_label', 'members_cta', 'paid_cta'];

    public const SECTION_SETTINGS = ['show_hero', 'show_excerpt', 'show_full_post'];

    public static function scopeFor(?int $newsletterId): string
    {
        return $newsletterId === null ? self::SCOPE_DEFAULT : self::SCOPE_DEFAULT.':'.$newsletterId;
    }

    public static function isNewsletterScope(string $scope): bool
    {
        return $scope === self::SCOPE_DEFAULT || preg_match('/^newsletter:[1-9][0-9]*$/', $scope) === 1;
    }

    public static function newsletterIdFromScope(string $scope): ?int
    {
        return str_starts_with($scope, self::SCOPE_DEFAULT.':') ? (int) substr($scope, strlen(self::SCOPE_DEFAULT) + 1) : null;
    }

    public static function subject(): string
    {
        return '{{ post_title }}';
    }

    public static function intro(): string
    {
        return '';
    }

    /**
     * @return array{sign_off: string, read_more_label: string, members_cta: string, paid_cta: string, show_hero: bool, show_excerpt: bool, show_full_post: bool}
     */
    public static function settings(): array
    {
        return [
            'sign_off' => "Thanks,  \n{{ site_name }}",
            'read_more_label' => 'Read the full story',
            'members_cta' => self::MEMBERS_CTA,
            'paid_cta' => self::PAID_CTA,
            'show_hero' => true,
            'show_excerpt' => false,
            'show_full_post' => true,
        ];
    }

    /**
     * Stored settings merged over the defaults, with unknown keys dropped.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array{sign_off: string, read_more_label: string, members_cta: string, paid_cta: string, show_hero: bool, show_excerpt: bool, show_full_post: bool}
     */
    public static function mergeSettings(?array $stored): array
    {
        $settings = self::settings();

        foreach (self::TEXT_SETTINGS as $key) {
            if (isset($stored[$key]) && is_string($stored[$key])) {
                $settings[$key] = $stored[$key];
            }
        }

        foreach (self::SECTION_SETTINGS as $key) {
            if (isset($stored[$key])) {
                $settings[$key] = (bool) $stored[$key];
            }
        }

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    public static function samplePlaceholders(): array
    {
        return [
            'post_title' => 'Rescued bearded dragons find new homes',
            'post_excerpt' => 'Six bearded dragons rescued in January have all been adopted.',
            'post_author' => 'Staff Demo',
            'read_more_url' => 'https://apesnews.org.uk/articles/rescued-dragons',
            'recipient_first_name' => 'Sam',
            'newsletter_name' => 'APES Shelter & Rescue',
            'channel_label' => 'APES Shelter & Rescue',
            'site_name' => (string) config('app.name'),
        ];
    }

    /**
     * Definition for TemplateRenderer::validate (subject and intro body).
     */
    public static function definition(): TemplateDefinition
    {
        return new TemplateDefinition(
            key: self::KEY,
            name: 'Newsletter email',
            group: 'newsletter',
            allowedPlaceholders: self::PLACEHOLDERS,
            requiredPlaceholders: [],
            defaultSubject: self::subject(),
            defaultBody: self::intro(),
            samplePlaceholders: self::samplePlaceholders(),
        );
    }
}
