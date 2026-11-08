<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    public const SCOPE_SYSTEM = 'system';

    protected $fillable = [
        'key',
        'scope',
        'subject',
        'body',
        'settings',
        'updated_by',
    ];

    protected $attributes = [
        'scope' => self::SCOPE_SYSTEM,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @param  Builder<EmailTemplate>  $query
     */
    public function scopeSystem(Builder $query): void
    {
        $query->where('scope', self::SCOPE_SYSTEM);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<EmailTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(EmailTemplateVersion::class)->latest('id');
    }
}
