<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\Feeds\RssFeedBuilder;
use Illuminate\Http\Response;

class RssController extends Controller
{
    public function __construct(private readonly RssFeedBuilder $feeds) {}

    public function index(): Response
    {
        $posts = Post::published()->with('author')->latest('published_at')->limit(50)->get();

        $xml = view('rss', $this->feeds->payload(
            (string) config('app.name'),
            url('/'),
            'APES Newsroom — stories from APES CIC, Shelter & Rescue, and Pet Care Clinic',
            url('/rss.xml'),
            $posts,
        ))->render();

        return response($xml, 200, ['Content-Type' => 'application/rss+xml']);
    }
}
