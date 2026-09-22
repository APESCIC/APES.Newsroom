<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Tag;
use App\Services\Feeds\RssFeedBuilder;
use Illuminate\Http\Response;

class TagRssController extends Controller
{
    public function __construct(private readonly RssFeedBuilder $feeds) {}

    public function show(string $slug): Response
    {
        $tag = Tag::query()->public()->where('slug', $slug)->firstOrFail();

        $posts = Post::published()
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $tag->id))
            ->with('author')
            ->latest('published_at')
            ->limit(50)
            ->get();

        $xml = view('rss', $this->feeds->payload(
            config('app.name').' — '.$tag->name,
            url('/tags/'.$tag->slug),
            'Posts tagged '.$tag->name,
            url('/tags/'.$tag->slug.'/rss.xml'),
            $posts,
        ))->render();

        return response($xml, 200, ['Content-Type' => 'application/rss+xml']);
    }
}
