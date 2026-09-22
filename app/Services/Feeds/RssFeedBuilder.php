<?php

namespace App\Services\Feeds;

use App\Models\Post;
use Illuminate\Support\Collection;

class RssFeedBuilder
{
    /**
     * @param  Collection<int, Post>  $posts
     * @return array{title: string, link: string, description: string, self: string, posts: Collection<int, Post>}
     */
    public function payload(
        string $title,
        string $link,
        string $description,
        string $selfUrl,
        Collection $posts,
    ): array {
        return [
            'title' => $title,
            'link' => $link,
            'description' => $description,
            'self' => $selfUrl,
            'posts' => $posts,
        ];
    }
}
