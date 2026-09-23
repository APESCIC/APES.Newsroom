<?php

namespace App\Http\Controllers\Api\Content\V1;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Models\Tag;
use App\Services\Publishing\PublicArticlePresenter;
use App\Services\Publishing\PublicPagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentResourceController extends Controller
{
    public function posts(Request $request, PublicArticlePresenter $articles): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = Post::query()
            ->published()
            ->with(['author', 'authors', 'tags'])
            ->orderByDesc('published_at')
            ->paginate($perPage);

        $data = $paginator->getCollection()->map(
            fn (Post $post) => $this->listShape($articles->payload($post, null))
        )->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function post(string $slug, PublicArticlePresenter $articles): JsonResponse
    {
        $post = Post::query()
            ->published()
            ->where('slug', $slug)
            ->with(['author', 'authors', 'tags'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->showShape($articles->payload($post, null)),
        ]);
    }

    public function pages(Request $request, PublicPagePresenter $pages): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = Page::query()
            ->published()
            ->with(['author'])
            ->orderByDesc('published_at')
            ->paginate($perPage);

        $data = $paginator->getCollection()->map(
            fn (Page $page) => $this->listShape($pages->payload($page, null))
        )->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function page(string $slug, PublicPagePresenter $pages): JsonResponse
    {
        $page = Page::query()
            ->published()
            ->where('slug', $slug)
            ->with(['author'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->showShape($pages->payload($page, null)),
        ]);
    }

    public function tags(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginator = Tag::query()
            ->public()
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Tag $tag) => [
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function tag(string $slug, PublicArticlePresenter $articles, Request $request): JsonResponse
    {
        $tag = Tag::query()->public()->where('slug', $slug)->firstOrFail();
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = Post::query()
            ->published()
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $tag->id))
            ->with(['author', 'authors', 'tags'])
            ->orderByDesc('published_at')
            ->paginate($perPage);

        $data = $paginator->getCollection()->map(
            fn (Post $post) => $this->listShape($articles->payload($post, null))
        )->values();

        return response()->json([
            'data' => [
                'tag' => [
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ],
                'posts' => $data,
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function listShape(array $payload): array
    {
        unset($payload['html'], $payload['gate']);

        return $payload;
    }

    /**
     * Content API always evaluates as an anonymous reader: HTML only when
     * visibility is public (presenters already omit HTML when gated).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function showShape(array $payload): array
    {
        if (($payload['visibility'] ?? null) !== 'public') {
            $payload['html'] = null;
            $payload['gated'] = true;
        }

        return $payload;
    }
}
