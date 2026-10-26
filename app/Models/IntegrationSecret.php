<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider', 'key', 'value', 'last_four', 'set_by', 'set_at', 'rotated_at'])]
#[Hidden(['value'])]
class IntegrationSecret extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'set_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public static function lastFourOf(string $value): string
    {
        return mb_strlen($value) > 8 ? mb_substr($value, -4) : '';
    }
}
