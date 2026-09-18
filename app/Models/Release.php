<?php

namespace App\Models;

use App\Enums\ReleaseChannel;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    public const CHANGE_TYPES = ['added', 'changed', 'fixed', 'removed', 'security'];

    public const TOPIC_TAGS = ['compliance', 'accessibility', 'public-facing', 'internal-only'];

    protected $fillable = [
        'version',
        'previous_version',
        'released_at',
        'channel',
        'version_type',
        'theme',
        'is_current',
        'is_published',
        'slug',
        'change_types',
        'topic_tags',
        'summary',
        'detailed_changes',
        'affected_areas',
        'version_decision',
        'validation',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'date',
            'channel' => ReleaseChannel::class,
            'is_current' => 'boolean',
            'is_published' => 'boolean',
            'change_types' => 'array',
            'topic_tags' => 'array',
            'detailed_changes' => 'array',
            'affected_areas' => 'array',
            'version_decision' => 'array',
            'validation' => 'array',
        ];
    }

    public static function slugFromVersion(string $version): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '', strtolower($version)) ?? '';

        return 'release-'.$normalized;
    }

    /**
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @return list<string>
     */
    public function filterTags(): array
    {
        $tags = [
            ...($this->change_types ?? []),
            ...($this->topic_tags ?? []),
            $this->channel->value,
        ];

        if ($this->is_current) {
            $tags[] = 'current';
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'previous_version' => $this->previous_version,
            'released_at' => $this->released_at?->toDateString(),
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'version_type' => $this->version_type,
            'theme' => $this->theme,
            'is_current' => $this->is_current,
            'slug' => $this->slug,
            'change_types' => $this->change_types ?? [],
            'topic_tags' => $this->topic_tags ?? [],
            'tags' => $this->filterTags(),
            'summary' => $this->summary,
            'detailed_changes' => $this->detailed_changes ?? [],
            'affected_areas' => $this->affected_areas ?? [],
            'version_decision' => $this->version_decision ?? [],
            'validation' => $this->validation ?? [],
            'search_text' => Str::lower(implode(' ', [
                $this->version,
                $this->previous_version ?? '',
                $this->version_type ?? '',
                $this->theme ?? '',
                $this->summary,
                implode(' ', $this->detailed_changes ?? []),
                implode(' ', $this->affected_areas ?? []),
                implode(' ', $this->version_decision ?? []),
                implode(' ', $this->validation ?? []),
                implode(' ', $this->change_types ?? []),
                implode(' ', $this->topic_tags ?? []),
            ])),
        ];
    }

    public function markAsCurrent(): void
    {
        static::query()->where('id', '!=', $this->id)->update(['is_current' => false]);
        $this->forceFill(['is_current' => true])->save();
    }
}
