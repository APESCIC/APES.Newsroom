<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));

        $posts = collect();

        if ($query !== '') {
            $escaped = addcslashes($query, '%_\\');
            $like = '%'.$escaped.'%';

            $posts = Post::published()
                ->where(function ($q) use ($like) {
                    $q->where('title', 'like', $like)
                        ->orWhere('excerpt', 'like', $like)
                        ->orWhere('body_text', 'like', $like);
                })
                ->orderByRaw(
                    'CASE WHEN title LIKE ? THEN 0 WHEN excerpt LIKE ? THEN 1 ELSE 2 END',
                    [$like, $like],
                )
                ->latest('published_at')
                ->limit(20)
                ->get()
                ->map(fn (Post $post) => [
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'excerpt' => $post->excerpt,
                    'published_at' => $post->published_at?->toIso8601String(),
                ]);
        }

        return Inertia::render('Search/Index', [
            'query' => $query,
            'results' => $posts,
        ]);
    }
}
