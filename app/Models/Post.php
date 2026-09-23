<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\ContentVisibility;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Services\EditorJs\BodyTextExtractor;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'ghost_id', 'author_id', 'title', 'slug', 'excerpt', 'content', 'body_text', 'status', 'visibility', 'channel',
    'hero_image', 'hero_image_alt', 'hero_image_caption', 'hero_image_credit',
    'meta_title', 'meta_description', 'canonical_url', 'published_at',
    'scheduled_for', 'email_on_publish', 'mailing_lists', 'newsletter_segment_id', 'review_notes', 'needs_import_review',
    'featured',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Post $post): void {
            if ($post->isDirty('content') || $post->body_text === null) {
                $content = is_array($post->content) ? $post->content : [];
                $post->body_text = app(BodyTextExtractor::class)->extract($content);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'status' => PostStatus::class,
            'visibility' => ContentVisibility::class,
            'channel' => Channel::class,
            'published_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'email_on_publish' => 'boolean',
            'mailing_lists' => 'array',
            'needs_import_review' => 'boolean',
            'featured' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_author');
    }

    /**
     * @return HasMany<PostRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    /**
     * @return BelongsTo<NewsletterSegment, $this>
     */
    public function newsletterSegment(): BelongsTo
    {
        return $this->belongsTo(NewsletterSegment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isEditableBy(User $user): bool
    {
        if ($user->role->atLeast(Role::Admin)) {
            return true;
        }

        return $this->author_id === $user->id && $user->role->atLeast(Role::Staff);
    }
}
