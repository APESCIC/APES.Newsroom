<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Models\Post;
use App\Services\Channels\PublicChannels;
use App\Services\Media\HeroImagePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const CHANNEL_SECTION_POSTS = 3;

    public function __construct(
        private readonly PublicChannels $channels,
        private readonly HeroImagePresenter $heroImages,
    ) {}

    public function __invoke(Request $request): Response
    {
        $featuredPost = Post::published()
            ->where('featured', true)
            ->with(['author', 'heroMedia'])
            ->latest('published_at')
            ->first();

        $recentQuery = Post::published()->with(['author', 'heroMedia'])->latest('published_at');
        if ($featuredPost) {
            $recentQuery->where('id', '!=', $featuredPost->id);
        }

        $recent = $recentQuery->limit($featuredPost ? 11 : 12)->get();

        if (! $featuredPost) {
            $featuredPost = $recent->first();
            $recent = $recent->skip(1)->values();
        }

        return Inertia::render('home', [
            'featured' => $featuredPost ? $this->cardPayload($featuredPost) : null,
            'recent' => $recent->map(fn (Post $post) => $this->cardPayload($post))->values(),
            'channels' => collect($this->channels->forHome())->map(fn (array $channel) => [
                ...$channel,
                'latest' => Post::published()
                    ->where('channel', Channel::from($channel['value']))
                    ->latest('published_at')
                    ->limit(self::CHANNEL_SECTION_POSTS)
                    ->get(['title', 'slug', 'published_at'])
                    ->map(fn (Post $post) => [
                        'title' => $post->title,
                        'slug' => $post->slug,
                        'published_at' => $post->published_at?->toIso8601String(),
                    ])
                    ->values(),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPayload(Post $post): array
    {
        return [
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'channel' => $this->channels->label($post->channel),
            'channel_slug' => $post->channel->slug(),
            'author' => $post->author->name,
            'published_at' => $post->published_at?->toIso8601String(),
            ...$this->heroImages->present($post),
            'hero_image_alt' => $post->hero_image_alt,
            'featured' => (bool) $post->featured,
        ];
    }
}
