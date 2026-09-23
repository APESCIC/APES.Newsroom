<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['newsletter_id', 'name', 'also_newsletter_id'])]
class NewsletterSegment extends Model
{
    /**
     * @return BelongsTo<Newsletter, $this>
     */
    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    /**
     * @return BelongsTo<Newsletter, $this>
     */
    public function alsoNewsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class, 'also_newsletter_id');
    }
}
