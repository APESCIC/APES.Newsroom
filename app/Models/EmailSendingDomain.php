<?php

namespace App\Models;

use App\Enums\SendingDomainStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'last_tested_at',
        'last_test_result',
        'track_opens',
        'track_clicks',
        'webhooks',
        'webhooks_checked_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SendingDomainStatus::class,
            'last_check' => 'array',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_test_result' => 'array',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'webhooks' => 'array',
            'webhooks_checked_at' => 'datetime',
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
     * @return HasMany<EmailSenderAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(EmailSenderAddress::class)->orderBy('address');
    }

    /**
     * Address suffixes on this domain: the domain itself and, when set, its
     * Mailgun domain (news@mg.example.org.uk belongs to example.org.uk).
     *
     * @return list<string>
     */
    public function addressSuffixes(): array
    {
        return array_values(array_unique(array_filter(['@'.$this->domain, $this->mailgun_domain ? '@'.$this->mailgun_domain : null])));
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
        $suffixes = $this->addressSuffixes();

        return Newsletter::query()
            ->where(function (Builder $query) use ($suffixes) {
                foreach ($suffixes as $suffix) {
                    $query->orWhereRaw('LOWER(sender_email) LIKE ?', ['%'.$suffix])
                        ->orWhereRaw('LOWER(reply_to) LIKE ?', ['%'.$suffix]);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'sender_email', 'reply_to', 'archived_at'])
            ->map(fn (Newsletter $newsletter) => [
                'id' => $newsletter->id,
                'name' => $newsletter->name,
                'archived' => $newsletter->archived_at !== null,
                'uses' => array_values(array_filter([
                    Str::endsWith(Str::lower((string) $newsletter->sender_email), $suffixes) ? 'from' : null,
                    Str::endsWith(Str::lower((string) $newsletter->reply_to), $suffixes) ? 'reply_to' : null,
                ])),
            ])
            ->all();
    }

    public function isUsedAsSender(): bool
    {
        $suffixes = $this->addressSuffixes();

        return Newsletter::query()
            ->active()
            ->where(function (Builder $query) use ($suffixes) {
                foreach ($suffixes as $suffix) {
                    $query->orWhereRaw('LOWER(sender_email) LIKE ?', ['%'.$suffix]);
                }
            })
            ->exists();
    }
}
