<?php

namespace App\Services\Publishing;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\EditorJs\BlockValidator;
use App\Services\Mailing\CampaignService;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditorialPostWriter
{
    public function __construct(
        private readonly BlockValidator $validator,
        private readonly CampaignService $campaigns,
        private readonly AuditLogger $audit,
        private readonly OutboundWebhookDispatcher $webhooks,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $actor, array $validated): Post
    {
        $content = $this->validator->validate($validated['content']);
        $tags = $validated['tags'] ?? [];
        $coAuthorIds = $validated['co_author_ids'] ?? [];
        unset($validated['tags'], $validated['co_author_ids'], $validated['expected_updated_at']);

        $post = Post::create([
            ...$validated,
            'content' => $content,
            'slug' => $validated['slug'] ?? Str::slug($validated['title']),
            'author_id' => $actor->id,
            'status' => PostStatus::Draft,
            'featured' => (bool) ($validated['featured'] ?? false),
        ]);

        $this->syncTags($post, $tags);
        $this->syncAuthors($post, $coAuthorIds);
        $this->saveRevision($post, $actor->id);

        return $post->fresh(['author', 'tags', 'authors']) ?? $post;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $actor, Post $post, array $validated): Post
    {
        if (! $post->isEditableBy($actor)) {
            abort(403);
        }

        $post->loadMissing(['tags', 'authors']);

        if (isset($validated['expected_updated_at'])) {
            $expected = $validated['expected_updated_at'];
            unset($validated['expected_updated_at']);
            if ($post->updated_at?->toIso8601String() !== $expected) {
                throw ValidationException::withMessages([
                    'conflict' => 'This post was edited elsewhere. Reload and retry.',
                ]);
            }
        }

        if (! isset($validated['content'])) {
            $validated['content'] = $post->content ?? ['blocks' => []];
        }
        if (! isset($validated['title'])) {
            $validated['title'] = $post->title;
        }
        if (! isset($validated['channel'])) {
            $validated['channel'] = $post->channel->value;
        }

        $content = $this->validator->validate($validated['content']);
        $tags = $validated['tags'] ?? $post->tags->pluck('name')->all();
        $coAuthorIds = $validated['co_author_ids'] ?? $post->authors->pluck('id')->all();
        unset($validated['tags'], $validated['co_author_ids']);

        $oldSlug = $post->slug;
        $wasPublished = $post->status === PostStatus::Published;

        $post->update([
            ...collect($validated)->except(['expected_updated_at'])->all(),
            'content' => $content,
            'featured' => (bool) ($validated['featured'] ?? $post->featured),
        ]);

        $this->syncTags($post, $tags);
        $this->syncAuthors($post, $coAuthorIds);
        $this->saveRevision($post, $actor->id);

        if ($wasPublished && $oldSlug !== $post->slug) {
            Redirect::query()->updateOrCreate(
                ['from_path' => '/articles/'.$oldSlug],
                ['to_path' => '/articles/'.$post->slug, 'status_code' => 301],
            );
        }

        return $post->fresh(['author', 'tags', 'authors']) ?? $post;
    }

    public function publish(User $actor, Post $post): Post
    {
        if (! $actor->role->atLeast(Role::Admin)) {
            abort(403);
        }

        $didPublish = false;
        $publishedPost = DB::transaction(function () use ($actor, $post, &$didPublish) {
            $lockedPost = Post::query()->lockForUpdate()->findOrFail($post->id);

            if ($lockedPost->status === PostStatus::Published) {
                return $lockedPost;
            }

            if ($lockedPost->status === PostStatus::Deleted) {
                throw ValidationException::withMessages([
                    'status' => 'Deleted posts cannot be published.',
                ]);
            }

            $lockedPost->update([
                'status' => PostStatus::Published,
                'published_at' => now(),
                'scheduled_for' => null,
                'review_notes' => null,
            ]);

            $this->campaigns->createFromPublishedPost($lockedPost->fresh(), $actor);
            $didPublish = true;

            return $lockedPost->fresh();
        });

        if ($didPublish) {
            $this->audit->record($actor, 'post.published', $publishedPost, []);
            $this->webhooks->dispatch(OutboundWebhookDispatcher::EVENT_POST_PUBLISHED, [
                'post_id' => $publishedPost->id,
                'slug' => $publishedPost->slug,
                'title' => $publishedPost->title,
                'status' => $publishedPost->status->value,
                'published_at' => $publishedPost->published_at?->toIso8601String(),
            ]);
        }

        return $publishedPost->fresh(['author', 'tags', 'authors']) ?? $publishedPost;
    }

    public function unpublish(User $actor, Post $post): Post
    {
        if (! $actor->role->atLeast(Role::Admin)) {
            abort(403);
        }

        $post->update([
            'status' => PostStatus::Unpublished,
            'scheduled_for' => null,
        ]);

        $this->audit->record($actor, 'post.unpublished', $post, []);

        return $post->fresh(['author', 'tags', 'authors']) ?? $post;
    }

    private function saveRevision(Post $post, int $editorId): void
    {
        PostRevision::create([
            'post_id' => $post->id,
            'editor_id' => $editorId,
            'content' => $post->content,
            'title' => $post->title,
        ]);
    }

    /**
     * @param  array<int, string>  $tagNames
     */
    private function syncTags(Post $post, array $tagNames): void
    {
        $ids = [];

        foreach ($tagNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $attrs = Tag::attributesFromName($name);
            $tag = Tag::query()->firstOrCreate(
                ['slug' => $attrs['slug']],
                ['name' => $attrs['name'], 'is_internal' => $attrs['is_internal']],
            );

            if ($tag->is_internal !== $attrs['is_internal'] || $tag->name !== $attrs['name']) {
                $tag->fill([
                    'name' => $attrs['name'],
                    'is_internal' => $attrs['is_internal'],
                ])->save();
            }

            $ids[] = $tag->id;
        }

        $post->tags()->sync($ids);
    }

    /**
     * @param  array<int, int|string>  $coAuthorIds
     */
    private function syncAuthors(Post $post, array $coAuthorIds): void
    {
        $ids = collect($coAuthorIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->push((int) $post->author_id)
            ->unique()
            ->values()
            ->all();

        $post->authors()->sync($ids);
    }
}
