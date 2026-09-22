<?php

namespace App\Services\Publishing;

use App\Models\Page;
use App\Services\EditorJs\BlockRenderer;

class PublicPagePresenter
{
    public function __construct(private readonly BlockRenderer $renderer) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(Page $page): array
    {
        $page->loadMissing(['author']);

        $url = route('pages.show', $page->slug, absolute: true);

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt,
            'html' => $this->renderer->toHtml($page->content ?? ['blocks' => []]),
            'author' => $page->author->name,
            'author_id' => $page->author_id,
            'published_at' => $page->published_at?->toIso8601String(),
            'meta_title' => $page->meta_title ?? $page->title,
            'meta_description' => $page->meta_description ?? $page->excerpt,
            'hero_image' => $this->nullableString($page->hero_image),
            'hero_image_alt' => $this->nullableString($page->hero_image_alt),
            'hero_image_caption' => $this->nullableString($page->hero_image_caption),
            'hero_image_credit' => $this->nullableString($page->hero_image_credit),
            'canonical_url' => $this->nullableString($page->canonical_url),
            'url' => $url,
        ];
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
