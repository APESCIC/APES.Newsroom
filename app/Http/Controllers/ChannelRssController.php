<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\Channels\PublicChannels;
use App\Services\Feeds\RssFeedBuilder;
use Illuminate\Http\Response;

class ChannelRssController extends Controller
{
    public function __construct(
        private readonly RssFeedBuilder $feeds,
        private readonly PublicChannels $channels,
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
            ->with('author')
            ->latest('published_at')
            ->limit(50)
            ->get();

        $xml = view('rss', $this->feeds->payload(
            config('app.name').' — '.$payload['label'],
            url('/'.$payload['slug']),
            $payload['description'] ?? 'Published posts from '.$payload['label'],
            url('/'.$payload['slug'].'/rss.xml'),
            $posts,
        ))->render();

        return response($xml, 200, ['Content-Type' => 'application/rss+xml']);
    }
}
