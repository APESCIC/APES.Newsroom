<?php

namespace App\Services\Membership;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\ContentView;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class MemberCrmService
{
    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     signed_up_at: string,
     *     membership_status: string,
     *     membership_label: string,
     *     is_paying: bool,
     *     plan_name: string|null,
     *     plan_interval: string|null,
     *     recent_reads: list<array{path: string, viewed_at: string, post_id: int|null, page_id: int|null}>
     * }>
     */
    public function paginate(?string $search, ?string $statusFilter, int $perPage = 20): LengthAwarePaginator
    {
        $query = User::query()
            ->where('role', Role::Public)
            ->with(['membership.plan'])
            ->orderByDesc('created_at');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $statusFilter = $statusFilter ?: 'all';
        if ($statusFilter === 'paying') {
            $query->whereHas('membership', fn (Builder $builder) => $builder->where('status', MembershipStatus::Active));
        } elseif ($statusFilter === 'free') {
            $query->where(function (Builder $builder): void {
                $builder->whereDoesntHave('membership')
                    ->orWhereHas('membership', fn (Builder $membership) => $membership->where('status', '!=', MembershipStatus::Active));
            });
        }

        /** @var LengthAwarePaginator<int, User> $page */
        $page = $query->paginate($perPage)->withQueryString();

        $userIds = $page->getCollection()->pluck('id')->all();
        $recentByUser = $this->recentReadsForUsers($userIds);

        return $page->through(function (User $user) use ($recentByUser): array {
            $membership = $user->membership;
            $status = $membership?->status ?? MembershipStatus::Free;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'signed_up_at' => $user->created_at?->toIso8601String() ?? '',
                'membership_status' => $status->value,
                'membership_label' => $status->label(),
                'is_paying' => $status->isPaying(),
                'plan_name' => $membership?->plan?->name,
                'plan_interval' => $membership?->plan?->interval ?? $membership?->interval,
                'recent_reads' => $recentByUser[$user->id] ?? [],
            ];
        });
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<array{path: string, viewed_at: string, post_id: int|null, page_id: int|null}>>
     */
    private function recentReadsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $views = ContentView::query()
            ->whereIn('user_id', $userIds)
            ->orderByDesc('viewed_at')
            ->get(['user_id', 'path', 'viewed_at', 'post_id', 'page_id']);

        $grouped = [];
        foreach ($views as $view) {
            $uid = (int) $view->user_id;
            if (! isset($grouped[$uid])) {
                $grouped[$uid] = [];
            }
            if (count($grouped[$uid]) >= 5) {
                continue;
            }
            $grouped[$uid][] = [
                'path' => $view->path,
                'viewed_at' => $view->viewed_at?->toIso8601String() ?? '',
                'post_id' => $view->post_id,
                'page_id' => $view->page_id,
            ];
        }

        return $grouped;
    }
}
