<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\Engagement\CommentService;
use App\Services\Engagement\ReactionService;
use App\Services\Publishing\PublicArticlePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArticleController extends Controller
{
    public function show(
        Request $request,
        string $slug,
        PublicArticlePresenter $articles,
        CommentService $comments,
        ReactionService $reactions,
    ): Response {
        $post = Post::published()
            ->where('slug', $slug)
            ->with('author', 'authors', 'tags')
            ->firstOrFail();

        return Inertia::render('Articles/Show', [
            'article' => $articles->payload($post, $request->user()),
            'comments' => $comments->approvedPayloadForPost($post),
            'reactions' => $reactions->countsForPost($post, $request->user()),
            'canEngage' => $request->user()?->hasVerifiedEmail() ?? false,
            'preview' => false,
            'status' => session('status'),
        ]);
    }
}
