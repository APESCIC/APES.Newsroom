<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\Channels\PublicChannels;
use App\Services\Media\HeroImagePresenter;
use App\Services\Seo\PageMetaFactory;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    public function __construct(
        private readonly PublicChannels $channels,
        private readonly HeroImagePresenter $heroImages,
        private readonly PageMetaFactory $meta,
    ) {}

    public function show(string $channel): Response
    {
        $newsletter = $this->channels->findBySlug($channel);

        if (! $newsletter) {
            abort(404);
        }

        $payload = $this->channels->present($newsletter);

        $posts = Post::published()
            ->where('channel', $newsletter->channel)
            ->with(['author', 'heroMedia'])
            ->orderByDesc('featured')
            ->latest('published_at')
            ->paginate(12)
            ->through(fn (Post $post) => [
                'title' => $post->title,
                'slug' => $post->slug,
                'excerpt' => $post->excerpt,
                'author' => $post->author->name,
                'published_at' => $post->published_at?->toIso8601String(),
                'featured' => (bool) $post->featured,
                ...$this->heroImages->present($post),
                'hero_image_alt' => $post->hero_image_alt,
            ]);

        $seo = $this->meta->forChannel(
            $payload['label'],
            $payload['slug'],
            $payload['description'] ?? null,
            url('/'.$payload['slug'].'/rss.xml'),
        );

        return Inertia::render('Channels/Show', [
            'channel' => $payload,
            'posts' => $posts,
            'seo' => [
                'title' => $seo['title'],
                'description' => $seo['description'],
                'url' => $seo['url'],
                'image' => $seo['image'],
                'rss' => $seo['rss'],
            ],
        ])->withViewData(['meta' => $seo]);
    }
}
