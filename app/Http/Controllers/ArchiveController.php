<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Seo\PageMetaFactory;
use Inertia\Inertia;
use Inertia\Response;

class ArchiveController extends Controller
{
    public function __construct(
        private readonly PageMetaFactory $meta,
    ) {}

    public function author(string $author): Response
    {
        $user = User::query()->where('id', $author)->orWhere('name', $author)->firstOrFail();

        $posts = Post::published()
            ->where(function ($query) use ($user) {
                $query->where('author_id', $user->id)
                    ->orWhereHas('authors', fn ($q) => $q->where('users.id', $user->id));
            })
            ->with('author')
            ->latest('published_at')
            ->paginate(12)
            ->through(fn (Post $post) => $this->card($post));

        return Inertia::render('Archives/Author', [
            'author' => ['id' => $user->id, 'name' => $user->name],
            'posts' => $posts,
        ])->withViewData(['meta' => $this->meta->forAuthor($user->name, $user->id)]);
    }

    public function tag(string $slug): Response
    {
        $tag = Tag::query()->public()->where('slug', $slug)->firstOrFail();

        $posts = Post::published()
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $tag->id))
            ->with('author')
            ->latest('published_at')
            ->paginate(12)
            ->through(fn (Post $post) => $this->card($post));

        return Inertia::render('Archives/Tag', [
            'tag' => ['name' => $tag->name, 'slug' => $tag->slug],
            'posts' => $posts,
        ])->withViewData(['meta' => $this->meta->forTag($tag->name, $tag->slug)]);
    }

    public function date(int $year, ?int $month = null): Response
    {
        abort_unless($year >= 2000 && $year <= (int) now()->year + 1, 404);
        if ($month !== null) {
            abort_unless($month >= 1 && $month <= 12, 404);
        }

        $query = Post::published()->whereYear('published_at', $year);
        if ($month !== null) {
            $query->whereMonth('published_at', $month);
        }

        $posts = $query->with('author')
            ->latest('published_at')
            ->paginate(12)
            ->through(fn (Post $post) => $this->card($post));

        $label = $month !== null
            ? sprintf('%04d-%02d', $year, $month)
            : (string) $year;
        $url = $month !== null
            ? url(sprintf('/%04d/%02d', $year, $month))
            : url(sprintf('/%04d', $year));

        return Inertia::render('Archives/Date', [
            'year' => $year,
            'month' => $month,
            'posts' => $posts,
        ])->withViewData(['meta' => $this->meta->forListing($label, $url, 'Articles from '.$label.' on APES Newsroom.')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Post $post): array
    {
        return [
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'channel' => $post->channel->label(),
            'channel_slug' => $post->channel->slug(),
            'author' => $post->author->name,
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }
}
