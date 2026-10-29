<?php

namespace App\Models;

use App\Enums\SenderAddressPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A From address an admin allows on an approved sending domain.
 */
class EmailSenderAddress extends Model
{
    protected $fillable = [
        'email_sending_domain_id',
        'address',
        'purpose',
        'label',
        'enabled',
        'last_tested_at',
        'last_test_result',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => SenderAddressPurpose::class,
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_result' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EmailSenderAddress $address) {
            $address->address = Str::lower(trim((string) $address->address));
        });
    }

    /**
     * @return BelongsTo<EmailSendingDomain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(EmailSendingDomain::class, 'email_sending_domain_id');
    }

    /**
     * @param  Builder<EmailSenderAddress>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * Enabled, on an enabled domain Mailgun has verified.
     */
    public function canSend(): bool
    {
        return $this->enabled && $this->domain->isVerified();
    }
}
