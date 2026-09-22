<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Models\Post;
use App\Services\Feeds\RssFeedBuilder;
use Illuminate\Http\Response;

class ChannelRssController extends Controller
{
    public function __construct(private readonly RssFeedBuilder $feeds) {}

    public function show(string $channel): Response
    {
        $channelEnum = Channel::fromSlug($channel);

        if (! $channelEnum) {
            abort(404);
        }

        $posts = Post::published()
            ->where('channel', $channelEnum)
            ->with('author')
            ->latest('published_at')
            ->limit(50)
            ->get();

        $xml = view('rss', $this->feeds->payload(
            config('app.name').' — '.$channelEnum->label(),
            url('/'.$channelEnum->slug()),
            'Published posts from '.$channelEnum->label(),
            url('/'.$channelEnum->slug().'/rss.xml'),
            $posts,
        ))->render();

        return response($xml, 200, ['Content-Type' => 'application/rss+xml']);
    }
}
