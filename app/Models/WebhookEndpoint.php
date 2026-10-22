<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'url', 'secret', 'events', 'is_active'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Last characters only, so admins can match a secret without seeing it.
     */
    public function secretHint(): string
    {
        return 'whsec_…'.substr((string) $this->secret, -4);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensFor(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
