<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Models\Post;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    public function show(string $channel): Response
    {
        $channelEnum = Channel::fromSlug($channel);

        if (! $channelEnum) {
            abort(404);
        }

        $posts = Post::published()
            ->where('channel', $channelEnum)
            ->with('author')
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
            ]);

        return Inertia::render('Channels/Show', [
            'channel' => [
                'slug' => $channelEnum->slug(),
                'label' => $channelEnum->label(),
            ],
            'posts' => $posts,
        ]);
    }
}
