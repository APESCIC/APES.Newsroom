<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['ghost_id', 'name', 'slug', 'is_internal'];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /**
     * @param  Builder<Tag>  $query
     * @return Builder<Tag>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * @return array{name: string, slug: string, is_internal: bool}
     */
    public static function attributesFromName(string $name): array
    {
        $trimmed = trim($name);
        $isInternal = str_starts_with($trimmed, '#');
        $display = $isInternal ? ltrim($trimmed, "# \t") : $trimmed;
        if ($display === '') {
            $display = $trimmed;
        }

        return [
            'name' => $isInternal ? '#'.$display : $display,
            'slug' => Str::slug($display) ?: Str::slug($trimmed) ?: 'tag',
            'is_internal' => $isInternal,
        ];
    }
}
