<?php

namespace App\Services\Publishing;

use App\Models\Post;
use App\Services\EditorJs\BlockRenderer;

class PublicArticlePresenter
{
    public function __construct(private readonly BlockRenderer $renderer) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(Post $post): array
    {
        $post->loadMissing(['author', 'authors', 'tags']);

        $url = route('articles.show', $post->slug, absolute: true);

        return [
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'html' => $this->renderer->toHtml($post->content ?? ['blocks' => []]),
            'channel' => $post->channel->label(),
            'channel_slug' => $post->channel->slug(),
            'author' => $post->author->name,
            'author_id' => $post->author_id,
            'authors' => $post->authors->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values()->all() ?: [[
                'id' => $post->author_id,
                'name' => $post->author->name,
            ]],
            'featured' => (bool) $post->featured,
            'published_at' => $post->published_at?->toIso8601String(),
            'meta_title' => $post->meta_title ?? $post->title,
            'meta_description' => $post->meta_description ?? $post->excerpt,
            'tags' => $post->tags
                ->filter(fn ($tag) => ! $tag->is_internal)
                ->map(fn ($tag) => [
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])->values()->all(),
            'hero_image' => $this->nullableString($post->hero_image),
            'hero_image_alt' => $this->nullableString($post->hero_image_alt),
            'hero_image_caption' => $this->nullableString($post->hero_image_caption),
            'hero_image_credit' => $this->nullableString($post->hero_image_credit),
            'canonical_url' => $this->nullableString($post->canonical_url),
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
