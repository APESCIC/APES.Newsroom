<?php

namespace App\Services\Analytics;

use App\Models\ContentView;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\Request;

class ContentViewRecorder
{
    public function record(Request $request, ?Post $post = null, ?Page $page = null): void
    {
        ContentView::query()->create([
            'path' => '/'.ltrim($request->path(), '/'),
            'post_id' => $post?->id,
            'page_id' => $page?->id,
            'user_id' => $request->user()?->id,
            'viewed_at' => now(),
        ]);
    }

    /**
     * @return array{views_7d: int, views_30d: int, signed_in_share_7d: float}
     */
    public function webSummary(): array
    {
        $views7 = ContentView::query()->where('viewed_at', '>=', now()->subDays(7))->count();
        $views30 = ContentView::query()->where('viewed_at', '>=', now()->subDays(30))->count();
        $signed7 = ContentView::query()
            ->where('viewed_at', '>=', now()->subDays(7))
            ->whereNotNull('user_id')
            ->count();

        return [
            'views_7d' => $views7,
            'views_30d' => $views30,
            'signed_in_share_7d' => $views7 > 0 ? (float) round(($signed7 / $views7) * 100, 1) : 0.0,
        ];
    }
}
