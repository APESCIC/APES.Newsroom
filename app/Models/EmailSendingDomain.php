<?php

namespace App\Models;

use App\Enums\SendingDomainStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailSendingDomain extends Model
{
    protected $fillable = [
        'domain',
        'label',
        'status',
        'mailgun_domain',
        'last_check',
        'last_checked_at',
        'verified_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SendingDomainStatus::class,
            'last_check' => 'array',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EmailSendingDomain $domain) {
            $domain->domain = Str::lower(trim((string) $domain->domain));
            if ($domain->mailgun_domain !== null) {
                $domain->mailgun_domain = Str::lower(trim($domain->mailgun_domain)) ?: null;
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<EmailSendingDomain>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('status', '!=', SendingDomainStatus::Disabled->value);
    }

    public function isEnabled(): bool
    {
        return $this->status !== SendingDomainStatus::Disabled;
    }

    public function isVerified(): bool
    {
        return $this->status === SendingDomainStatus::Verified;
    }

    /**
     * Newsletters whose From or reply-to address is on this domain.
     *
     * @return list<array{id: int, name: string, archived: bool, uses: list<string>}>
     */
    public function newslettersUsing(): array
    {
        $suffix = '@'.$this->domain;

        return Newsletter::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(sender_email) LIKE ?', ['%'.$suffix])
                ->orWhereRaw('LOWER(reply_to) LIKE ?', ['%'.$suffix]))
            ->orderBy('name')
            ->get(['id', 'name', 'sender_email', 'reply_to', 'archived_at'])
            ->map(fn (Newsletter $newsletter) => [
                'id' => $newsletter->id,
                'name' => $newsletter->name,
                'archived' => $newsletter->archived_at !== null,
                'uses' => array_values(array_filter([
                    Str::endsWith(Str::lower((string) $newsletter->sender_email), $suffix) ? 'from' : null,
                    Str::endsWith(Str::lower((string) $newsletter->reply_to), $suffix) ? 'reply_to' : null,
                ])),
            ])
            ->all();
    }

    public function isUsedAsSender(): bool
    {
        return Newsletter::query()
            ->active()
            ->whereRaw('LOWER(sender_email) LIKE ?', ['%@'.$this->domain])
            ->exists();
    }
}
