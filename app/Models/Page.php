<?php

namespace App\Models;

use App\Enums\ContentVisibility;
use App\Enums\PostStatus;
use App\Enums\Role;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'ghost_id', 'author_id', 'title', 'slug', 'excerpt', 'content', 'status', 'visibility',
    'hero_image', 'hero_image_alt', 'hero_image_caption', 'hero_image_credit',
    'meta_title', 'meta_description', 'canonical_url', 'published_at',
])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'status' => PostStatus::class,
            'visibility' => ContentVisibility::class,
            'published_at' => 'datetime',
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
     * @param  Builder<Page>  $query
     * @return Builder<Page>
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
