<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where an uploaded image is used, so it is never deleted silently (#537).
 *
 * Body references are matched on the asset UUID, which appears in every
 * variant path and survives JSON slash escaping in stored content.
 */
class MediaUsageFinder
{
    /**
     * @return list<array{type: string, id: int, title: string, status: string, as_hero: bool, in_body: bool, edit_url: string}>
     */
    public function find(MediaAsset $asset): array
    {
        $usage = [];

        foreach ([Post::class => 'post', Page::class => 'page'] as $model => $type) {
            $model::query()
                ->where(fn (Builder $query) => $query
                    ->where('hero_media_id', $asset->id)
                    ->orWhere('content', 'like', '%'.$asset->uuid.'%'))
                ->orderBy('title')
                ->get(['id', 'title', 'status', 'hero_media_id', 'content'])
                ->each(function (Post|Page $item) use (&$usage, $asset, $type) {
                    $usage[] = [
                        'type' => $type,
                        'id' => $item->id,
                        'title' => $item->title,
                        'status' => $item->status->value,
                        'as_hero' => $item->hero_media_id === $asset->id,
                        'in_body' => str_contains((string) json_encode($item->content), $asset->uuid),
                        'edit_url' => $type === 'post' ? "/staff/posts/{$item->id}/edit" : "/staff/pages/{$item->id}/edit",
                    ];
                });
        }

        return $usage;
    }

    /**
     * @param  iterable<MediaAsset>  $assets
     * @return array<int, int> asset id => number of posts and pages using it
     */
    public function counts(iterable $assets): array
    {
        $counts = [];
        foreach ($assets as $asset) {
            $counts[$asset->id] = count($this->find($asset));
        }

        return $counts;
    }
}
