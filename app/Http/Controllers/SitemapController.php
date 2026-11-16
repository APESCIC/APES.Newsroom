<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Models\Tag;
use App\Services\Channels\PublicChannels;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $xml = view('seo.sitemap-index', [
            'sitemaps' => [
                ['loc' => url('/sitemap-posts.xml'), 'lastmod' => now()->toAtomString()],
                ['loc' => url('/sitemap-pages.xml'), 'lastmod' => now()->toAtomString()],
                ['loc' => url('/sitemap-channels.xml'), 'lastmod' => now()->toAtomString()],
                ['loc' => url('/sitemap-tags.xml'), 'lastmod' => now()->toAtomString()],
                ['loc' => url('/sitemap-news.xml'), 'lastmod' => now()->toAtomString()],
            ],
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function posts(): Response
    {
        $posts = Post::published()
            ->where(function ($query) {
                $query->whereNull('seo_noindex')->orWhere('seo_noindex', false);
            })
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at']);

        return $this->urlset(view('seo.sitemap-posts', ['posts' => $posts])->render());
    }

    public function pages(): Response
    {
        $pages = Page::published()
            ->where(function ($query) {
                $query->whereNull('seo_noindex')->orWhere('seo_noindex', false);
            })
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at']);

        return $this->urlset(view('seo.sitemap-pages', ['pages' => $pages])->render());
    }

    public function channels(PublicChannels $channels): Response
    {
        return $this->urlset(view('seo.sitemap-channels', [
            'channels' => array_column($channels->forListing(), 'slug'),
        ])->render());
    }

    public function tags(): Response
    {
        $tags = Tag::query()
            ->public()
            ->whereHas('posts', fn ($q) => $q->published()->where(function ($query) {
                $query->whereNull('seo_noindex')->orWhere('seo_noindex', false);
            }))
            ->orderBy('slug')
            ->get(['slug', 'updated_at']);

        return $this->urlset(view('seo.sitemap-tags', ['tags' => $tags])->render());
    }

    public function news(): Response
    {
        $posts = Post::published()
            ->where(function ($query) {
                $query->whereNull('seo_noindex')->orWhere('seo_noindex', false);
            })
            ->where('published_at', '>=', now()->subHours(48))
            ->with('author')
            ->orderByDesc('published_at')
            ->get(['id', 'title', 'slug', 'published_at', 'updated_at', 'channel', 'author_id']);

        return $this->urlset(view('seo.sitemap-news', ['posts' => $posts])->render());
    }

    private function urlset(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
