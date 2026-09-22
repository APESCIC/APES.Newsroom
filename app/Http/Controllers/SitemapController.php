<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $posts = Post::published()->orderByDesc('published_at')->get(['slug', 'updated_at', 'published_at']);
        $pages = Page::published()->orderByDesc('published_at')->get(['slug', 'updated_at', 'published_at']);

        $xml = view('sitemap', ['posts' => $posts, 'pages' => $pages])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
