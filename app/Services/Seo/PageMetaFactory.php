<?php

namespace App\Services\Seo;

use App\Models\Page;
use App\Models\Post;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;

/**
 * Builds the Blade `$meta` array for public (and preview) Inertia responses.
 *
 * @phpstan-type MetaArray array{
 *     title: string,
 *     description: string,
 *     url: string,
 *     image: string,
 *     image_width: int,
 *     image_height: int,
 *     image_alt: string,
 *     type: string,
 *     site_name: string,
 *     locale: string,
 *     twitter_card: string,
 *     twitter_site: ?string,
 *     robots: ?string,
 *     hreflang: string,
 *     rss: ?string,
 *     article: ?array{
 *         published_time: ?string,
 *         modified_time: ?string,
 *         author: ?string,
 *         section: ?string,
 *         tags: list<string>
 *     },
 *     json_ld: list<array<string, mixed>>,
 *     verification: array{google: ?string, bing: ?string}
 * }
 */
class PageMetaFactory
{
    public function __construct(
        private readonly MetaGenerator $generator,
        private readonly JsonLdBuilder $jsonLd,
        private readonly SocialCardService $socialCards,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return MetaArray
     */
    public function forHome(): array
    {
        $description = $this->defaultDescription();
        $image = $this->socialCards->defaultShareImage('APES Newsroom');

        return $this->base(
            title: $this->generator->title('APES Newsroom'),
            description: $description,
            url: url('/'),
            image: $image,
            type: 'website',
            jsonLd: $this->jsonLd->forHome(),
        );
    }

    /**
     * @return MetaArray
     */
    public function forArticle(Post $post, bool $preview = false): array
    {
        $auto = $this->generator->forEditor($post->title, $post->excerpt, is_array($post->content) ? $post->content : null);
        $title = $this->firstFilled($post->og_title, $post->meta_title, $auto['meta_title']);
        $description = $this->firstFilled($post->og_description, $post->meta_description, $auto['meta_description']);
        $twitterTitle = $this->firstFilled($post->twitter_title, $title);
        $twitterDescription = $this->firstFilled($post->twitter_description, $description);

        $canonical = $this->canonical($post->canonical_url, route('articles.show', $post->slug, absolute: true));
        $image = $this->socialCards->forPost($post, $post->title);
        $robots = $preview || (bool) ($post->seo_noindex ?? false) ? 'noindex,nofollow' : null;

        $tags = $post->relationLoaded('tags')
            ? $post->tags->filter(fn ($tag) => ! $tag->is_internal)->pluck('name')->filter()->values()->all()
            : [];

        $meta = $this->base(
            title: $title,
            description: $description,
            url: $canonical,
            image: $image,
            type: 'article',
            robots: $robots,
            jsonLd: $preview ? [] : $this->jsonLd->forArticle($post, $canonical, $image['url'], $description),
            twitterTitle: $twitterTitle,
            twitterDescription: $twitterDescription,
            twitterImage: $this->firstFilled($post->twitter_image, $image['url']),
        );

        $meta['article'] = [
            'published_time' => $post->published_at?->toIso8601String(),
            'modified_time' => ($post->updated_at ?? $post->published_at)?->toIso8601String(),
            'author' => $post->author?->name,
            'section' => $post->channel->label(),
            'tags' => $tags,
        ];

        return $meta;
    }

    /**
     * @return MetaArray
     */
    public function forPage(Page $page, bool $preview = false): array
    {
        $auto = $this->generator->forEditor($page->title, $page->excerpt, is_array($page->content) ? $page->content : null);
        $title = $this->firstFilled($page->og_title, $page->meta_title, $auto['meta_title']);
        $description = $this->firstFilled($page->og_description, $page->meta_description, $auto['meta_description']);
        $canonical = $this->canonical($page->canonical_url, route('pages.show', $page->slug, absolute: true));
        $image = $this->socialCards->forPage($page, $page->title);
        $robots = $preview || (bool) ($page->seo_noindex ?? false) ? 'noindex,nofollow' : null;

        return $this->base(
            title: $title,
            description: $description,
            url: $canonical,
            image: $image,
            type: 'website',
            robots: $robots,
            jsonLd: $preview ? [] : $this->jsonLd->forPage($page, $canonical, $image['url'], $description),
            twitterTitle: $this->firstFilled($page->twitter_title, $title),
            twitterDescription: $this->firstFilled($page->twitter_description, $description),
            twitterImage: $this->firstFilled($page->twitter_image, $image['url']),
        );
    }

    /**
     * @return MetaArray
     */
    public function forChannel(string $label, string $slug, ?string $description, string $rssUrl): array
    {
        $title = $this->generator->title($label);
        $desc = $this->firstFilled($description, 'Stories from '.$label.' on APES Newsroom.');
        $image = $this->socialCards->defaultShareImage($label);
        $url = url('/'.$slug);

        return $this->base(
            title: $title,
            description: $desc,
            url: $url,
            image: $image,
            type: 'website',
            rss: $rssUrl,
            jsonLd: $this->jsonLd->withBreadcrumbs(
                [$this->jsonLd->organization(), $this->jsonLd->webSite()],
                [['name' => $label, 'url' => $url]],
            ),
        );
    }

    /**
     * @return MetaArray
     */
    public function forTag(string $name, string $slug): array
    {
        $url = url('/tags/'.$slug);
        $noindex = (bool) $this->settings->get(SettingDefinitions::SEO_NOINDEX_TAG_PAGES);

        return $this->base(
            title: $this->generator->title($name),
            description: 'Articles tagged '.$name.' on APES Newsroom.',
            url: $url,
            image: $this->socialCards->defaultShareImage($name),
            type: 'website',
            robots: $noindex ? 'noindex,follow' : null,
            jsonLd: $this->jsonLd->withBreadcrumbs(
                [$this->jsonLd->organization(), $this->jsonLd->webSite()],
                [['name' => $name, 'url' => $url]],
            ),
        );
    }

    /**
     * @return MetaArray
     */
    public function forAuthor(string $name, int|string $id): array
    {
        $url = url('/authors/'.$id);
        $noindex = (bool) $this->settings->get(SettingDefinitions::SEO_NOINDEX_AUTHOR_PAGES);

        return $this->base(
            title: $this->generator->title($name),
            description: 'Articles by '.$name.' on APES Newsroom.',
            url: $url,
            image: $this->socialCards->defaultShareImage($name),
            type: 'website',
            robots: $noindex ? 'noindex,follow' : null,
            jsonLd: $this->jsonLd->withBreadcrumbs(
                [$this->jsonLd->organization(), $this->jsonLd->webSite()],
                [['name' => $name, 'url' => $url]],
            ),
        );
    }

    /**
     * @return MetaArray
     */
    public function forSearch(): array
    {
        return $this->base(
            title: $this->generator->title('Search'),
            description: 'Search APES Newsroom.',
            url: url('/search'),
            image: $this->socialCards->defaultShareImage('Search'),
            type: 'website',
            robots: 'noindex,nofollow',
            jsonLd: [],
        );
    }

    /**
     * @return MetaArray
     */
    public function forListing(string $title, string $url, string $description): array
    {
        return $this->base(
            title: $this->generator->title($title),
            description: $description,
            url: $url,
            image: $this->socialCards->defaultShareImage($title),
            type: 'website',
            jsonLd: $this->jsonLd->withBreadcrumbs(
                [$this->jsonLd->organization(), $this->jsonLd->webSite()],
                [['name' => $title, 'url' => $url]],
            ),
        );
    }

    /**
     * @return MetaArray
     */
    public function forAccount(string $title = 'Your account'): array
    {
        return $this->base(
            title: $this->generator->title($title),
            description: 'Your APES Newsroom account.',
            url: url('/account'),
            image: $this->socialCards->defaultShareImage($title),
            type: 'website',
            robots: 'noindex,nofollow',
            jsonLd: [],
        );
    }

    /**
     * @return MetaArray
     */
    public function forChangeLogHub(): array
    {
        $url = url('/change-log-hub');

        return $this->base(
            title: $this->generator->title('Change Log Hub'),
            description: 'Release notes for APES Newsroom.',
            url: $url,
            image: $this->socialCards->defaultShareImage('Change Log Hub'),
            type: 'website',
            jsonLd: $this->jsonLd->withBreadcrumbs(
                [$this->jsonLd->organization(), $this->jsonLd->webSite()],
                [['name' => 'Change Log Hub', 'url' => $url]],
            ),
        );
    }

    /**
     * @param  array{url: string, width: int, height: int, alt: string, from_hero: bool}  $image
     * @param  list<array<string, mixed>>  $jsonLd
     * @return MetaArray
     */
    private function base(
        string $title,
        string $description,
        string $url,
        array $image,
        string $type,
        ?string $robots = null,
        ?string $rss = null,
        array $jsonLd = [],
        ?string $twitterTitle = null,
        ?string $twitterDescription = null,
        ?string $twitterImage = null,
    ): array {
        $handle = ltrim((string) $this->settings->get(SettingDefinitions::SEO_TWITTER_HANDLE), '@');

        return [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'image' => $image['url'],
            'image_width' => $image['width'],
            'image_height' => $image['height'],
            'image_alt' => $image['alt'],
            'type' => $type,
            'site_name' => 'APES Newsroom',
            'locale' => 'en_GB',
            'twitter_card' => 'summary_large_image',
            'twitter_site' => $handle !== '' ? '@'.$handle : null,
            'twitter_title' => $twitterTitle ?? $title,
            'twitter_description' => $twitterDescription ?? $description,
            'twitter_image' => $twitterImage ?? $image['url'],
            'robots' => $robots,
            'hreflang' => 'en-GB',
            'rss' => $rss,
            'article' => null,
            'json_ld' => $jsonLd,
            'verification' => [
                'google' => $this->nullableString($this->settings->get(SettingDefinitions::SEO_GOOGLE_SITE_VERIFICATION)),
                'bing' => $this->nullableString($this->settings->get(SettingDefinitions::SEO_BING_SITE_VERIFICATION)),
            ],
        ];
    }

    private function defaultDescription(): string
    {
        $configured = $this->nullableString($this->settings->get(SettingDefinitions::SEO_DEFAULT_DESCRIPTION));

        return $configured ?? 'News and updates from APES — Association of Pet Enthusiasts and Sanctuaries.';
    }

    private function canonical(?string $override, string $fallback): string
    {
        $clean = $this->nullableString($override);
        if ($clean === null || str_contains($clean, '__GHOST_URL__')) {
            return $this->normalizeUrl($fallback);
        }

        return $this->normalizeUrl($clean);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        $normalized = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $normalized .= ':'.$parts['port'];
        }
        $normalized .= $path;

        return $normalized;
    }

    private function firstFilled(?string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
