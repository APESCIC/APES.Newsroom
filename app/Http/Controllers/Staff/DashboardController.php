<?php

namespace App\Http\Controllers\Staff;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Services\Analytics\MetricsDashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const LIST_LIMIT = 5;

    private const RECENT_LIMIT = 8;

    public function __invoke(Request $request, MetricsDashboard $metrics): Response
    {
        /** @var User $user */
        $user = $request->user();
        $canReview = $user->can('review', Post::class);
        $isAdmin = $user->can('access-admin-area');

        return Inertia::render('Staff/Dashboard', [
            'counts' => $this->counts($user),
            'attention' => [
                'review' => $canReview
                    ? $this->list(Post::query()->where('status', PostStatus::InReview)->oldest('updated_at'))
                    : null,
                'drafts' => $this->list(Post::query()
                    ->where('author_id', $user->id)
                    ->where('status', PostStatus::Draft)
                    ->latest('updated_at')),
                'scheduled' => $this->list($this->visiblePosts($user)
                    ->where('status', PostStatus::Scheduled)
                    ->orderBy('scheduled_for')),
            ],
            'recent' => $this->list($this->visiblePosts($user)
                ->where('status', '!=', PostStatus::Deleted)
                ->latest('updated_at')
                ->latest('id'), self::RECENT_LIMIT),
            'insights' => [
                'web' => $metrics->web(),
                'newsletters' => $metrics->newsletters(),
                'subscriptions' => $isAdmin ? $metrics->subscriptions() : null,
            ],
            'canReview' => $canReview,
        ]);
    }

    /**
     * Posts this user may see in the posts list: everything for admins, their own otherwise.
     *
     * @return Builder<Post>
     */
    private function visiblePosts(User $user): Builder
    {
        $query = Post::query();

        if ($user->cannot('viewAll', Post::class)) {
            $query->where('author_id', $user->id);
        }

        return $query;
    }

    /**
     * @return array{draft: int, in_review: int, scheduled: int, published_7d: int}
     */
    private function counts(User $user): array
    {
        $row = $this->visiblePosts($user)
            ->toBase()
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as draft', [PostStatus::Draft->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as in_review', [PostStatus::InReview->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as scheduled', [PostStatus::Scheduled->value])
            ->selectRaw(
                'sum(case when status = ? and published_at >= ? then 1 else 0 end) as published_7d',
                [PostStatus::Published->value, now()->subDays(7)],
            )
            ->first();

        return [
            'draft' => (int) ($row->draft ?? 0),
            'in_review' => (int) ($row->in_review ?? 0),
            'scheduled' => (int) ($row->scheduled ?? 0),
            'published_7d' => (int) ($row->published_7d ?? 0),
        ];
    }

    /**
     * @param  Builder<Post>  $query
     * @return list<array<string, mixed>>
     */
    private function list(Builder $query, int $limit = self::LIST_LIMIT): array
    {
        return $query
            ->with('author:id,name')
            ->limit($limit)
            ->get()
            ->map(fn (Post $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status->value,
                'channel' => $post->channel->value,
                'author' => $post->author?->name,
                'updated_at' => $post->updated_at?->toIso8601String(),
                'scheduled_for' => $post->scheduled_for?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
