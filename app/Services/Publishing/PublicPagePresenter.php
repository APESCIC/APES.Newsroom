<?php

namespace App\Services\Publishing;

use App\Enums\ContentVisibility;
use App\Models\Page;
use App\Models\User;
use App\Services\EditorJs\BlockRenderer;
use App\Services\Media\HeroImagePresenter;
use App\Services\Membership\ContentAccessService;

class PublicPagePresenter
{
    public function __construct(
        private readonly BlockRenderer $renderer,
        private readonly ContentAccessService $access,
        private readonly HeroImagePresenter $heroImages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(Page $page, ?User $viewer = null): array
    {
        $page->loadMissing(['author', 'heroMedia']);

        $url = route('pages.show', $page->slug, absolute: true);
        $visibility = $page->visibility instanceof ContentVisibility
            ? $page->visibility
            : ContentVisibility::Public;
        $gate = $this->access->evaluate($visibility, $viewer);
        $html = $gate['allowed']
            ? $this->renderer->toHtml($page->content ?? ['blocks' => []])
            : null;

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt,
            'html' => $html,
            'visibility' => $visibility->value,
            'gated' => ! $gate['allowed'],
            'gate' => $gate['allowed'] ? null : [
                'reason' => $gate['reason'],
                'cta' => $gate['cta'],
            ],
            'author' => $page->author->name,
            'author_id' => $page->author_id,
            'published_at' => $page->published_at?->toIso8601String(),
            'meta_title' => $page->meta_title ?? $page->title,
            'meta_description' => $page->meta_description ?? $page->excerpt,
            ...$this->heroImages->present($page),
            'hero_image_alt' => $this->nullableString($page->hero_image_alt),
            'hero_image_caption' => $this->nullableString($page->hero_image_caption),
            'hero_image_credit' => $this->nullableString($page->hero_image_credit),
            'canonical_url' => $this->canonicalUrl($page->canonical_url),
            'url' => $url,
        ];
    }

    private function canonicalUrl(mixed $value): ?string
    {
        $url = $this->nullableString($value);

        return $url !== null && str_contains($url, '__GHOST_URL__') ? null : $url;
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
