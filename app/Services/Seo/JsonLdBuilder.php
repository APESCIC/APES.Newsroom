<?php

namespace App\Services\Seo;

use App\Models\Page;
use App\Models\Post;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;

/**
 * schema.org JSON-LD graphs for server HTML (#671).
 */
class JsonLdBuilder
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forHome(): array
    {
        return [
            $this->organization(),
            $this->webSite(),
        ];
    }

    /**
     * @param  array{name: string, url: string}  $items  breadcrumb crumbs after Home
     * @return list<array<string, mixed>>
     */
    public function withBreadcrumbs(array $graph, array $items): array
    {
        $graph[] = $this->breadcrumbList($items);

        return $graph;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forArticle(Post $post, string $canonicalUrl, ?string $imageUrl, string $description): array
    {
        $post->loadMissing(['author', 'tags']);

        $article = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $post->title,
            'description' => $description,
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => ($post->updated_at ?? $post->published_at)?->toIso8601String(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonicalUrl,
            ],
            'url' => $canonicalUrl,
            'author' => [
                '@type' => 'Person',
                'name' => $post->author->name,
            ],
            'publisher' => $this->organizationRef(),
            'articleSection' => $post->channel->label(),
            'inLanguage' => 'en-GB',
        ];

        if ($imageUrl) {
            $article['image'] = [$imageUrl];
        }

        $keywords = $post->tags
            ->filter(fn ($tag) => ! $tag->is_internal)
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
        if ($keywords !== []) {
            $article['keywords'] = implode(', ', $keywords);
        }

        return $this->withBreadcrumbs(
            [$this->organization(), $this->webSite(), $article],
            [
                ['name' => $post->channel->label(), 'url' => url('/'.$post->channel->slug())],
                ['name' => $post->title, 'url' => $canonicalUrl],
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPage(Page $page, string $canonicalUrl, ?string $imageUrl, string $description): array
    {
        $page->loadMissing(['author']);

        $webPage = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $page->title,
            'description' => $description,
            'url' => $canonicalUrl,
            'datePublished' => $page->published_at?->toIso8601String(),
            'dateModified' => ($page->updated_at ?? $page->published_at)?->toIso8601String(),
            'inLanguage' => 'en-GB',
            'isPartOf' => ['@id' => url('/').'#website'],
            'publisher' => $this->organizationRef(),
        ];

        if ($imageUrl) {
            $webPage['image'] = [$imageUrl];
        }

        if ($page->author) {
            $webPage['author'] = [
                '@type' => 'Person',
                'name' => $page->author->name,
            ];
        }

        return $this->withBreadcrumbs(
            [$this->organization(), $this->webSite(), $webPage],
            [
                ['name' => $page->title, 'url' => $canonicalUrl],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $org = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => url('/').'#organization',
            'name' => 'APES Newsroom',
            'url' => url('/'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => url('/brand/apes-logo-square.png'),
            ],
        ];

        $sameAs = array_values(array_filter([
            $this->stringSetting(SettingDefinitions::SEO_FACEBOOK_URL),
            $this->stringSetting(SettingDefinitions::SEO_LINKEDIN_URL),
            $this->stringSetting(SettingDefinitions::SEO_YOUTUBE_URL),
            $this->stringSetting(SettingDefinitions::SEO_INSTAGRAM_URL),
            $this->twitterProfileUrl(),
        ]));

        if ($sameAs !== []) {
            $org['sameAs'] = $sameAs;
        }

        return $org;
    }

    /**
     * @return array<string, mixed>
     */
    public function webSite(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => 'APES Newsroom',
            'url' => url('/'),
            'inLanguage' => 'en-GB',
            'publisher' => $this->organizationRef(),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => url('/search').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    public function breadcrumbList(array $items): array
    {
        $elements = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => url('/'),
            ],
        ];

        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 2,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * @return array{ '@type': string, '@id': string }
     */
    private function organizationRef(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => url('/').'#organization',
        ];
    }

    private function stringSetting(string $key): ?string
    {
        $value = trim((string) $this->settings->get($key));

        return $value !== '' ? $value : null;
    }

    private function twitterProfileUrl(): ?string
    {
        $handle = ltrim((string) $this->settings->get(SettingDefinitions::SEO_TWITTER_HANDLE), '@');
        if ($handle === '') {
            return null;
        }

        return 'https://x.com/'.$handle;
    }
}
