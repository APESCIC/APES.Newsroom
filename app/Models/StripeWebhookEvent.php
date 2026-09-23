<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_id', 'type', 'processed_at'])]
class StripeWebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
