<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $featuredPost = Post::published()
            ->where('featured', true)
            ->with('author')
            ->latest('published_at')
            ->first();

        $recentQuery = Post::published()->with('author')->latest('published_at');
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
            'channels' => collect(Channel::cases())->map(fn (Channel $c) => [
                'slug' => $c->slug(),
                'label' => $c->label(),
            ]),
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
            'channel' => $post->channel->label(),
            'channel_slug' => $post->channel->slug(),
            'author' => $post->author->name,
            'published_at' => $post->published_at?->toIso8601String(),
            'hero_image' => $post->hero_image,
            'hero_image_alt' => $post->hero_image_alt,
            'featured' => (bool) $post->featured,
        ];
    }
}
