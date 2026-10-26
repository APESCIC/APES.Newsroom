<?php

namespace App\Models;

use App\Support\EmailAddress;
use Database\Factories\MailingContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

#[Fillable(['email', 'email_normalized', 'name', 'user_id'])]
class MailingContact extends Model
{
    /** @use HasFactory<MailingContactFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (MailingContact $contact): void {
            if ($contact->email !== null) {
                $normalized = EmailAddress::normalize((string) $contact->email);
                $contact->email = $normalized;
                $contact->email_normalized = $normalized;
            }
        });
    }

    /**
     * @param  Builder<MailingContact>  $query
     * @return Builder<MailingContact>
     */
    public function scopeMailingOnly(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * @param  Builder<MailingContact>  $query
     * @return Builder<MailingContact>
     */
    public function scopeForNormalizedEmail(Builder $query, string $email): Builder
    {
        return $query->where('email_normalized', EmailAddress::normalize($email));
    }

    public function isMailingOnly(): bool
    {
        return $this->user_id === null;
    }

    public static function findByEmail(string $email): ?self
    {
        return static::query()->forNormalizedEmail($email)->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MailingListSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MailingListSubscription::class);
    }

    /**
     * @return HasMany<ConsentEvent, $this>
     */
    public function consentEvents(): HasMany
    {
        return $this->hasMany(ConsentEvent::class);
    }

    /**
     * Route mail notifications to the contact email.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
