<?php

namespace App\Services\Mailing;

use App\Enums\SubscriptionStatus;
use App\Models\MailingContact;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Attach a mailing contact to a user by normalised email after ownership is proven.
 */
class LinkMailingContactService
{
    /**
     * Link the contact for the user's email. Idempotent when already linked.
     * Never creates a duplicate contact for an existing normalised email.
     *
     * @throws RuntimeException when the contact is linked to a different user
     */
    public function link(User $user, bool $createIfMissing = true): LinkMailingContactResult
    {
        if ($user->email_verified_at === null) {
            throw new RuntimeException('Cannot link mailing contact before the email is verified.');
        }

        $email = EmailAddress::normalize((string) $user->email);

        return DB::transaction(function () use ($user, $email, $createIfMissing) {
            $contact = MailingContact::query()
                ->forNormalizedEmail($email)
                ->lockForUpdate()
                ->first();

            $wasPreExisting = $contact !== null;
            $hadSubscriptions = $wasPreExisting
                && $contact->subscriptions()
                    ->whereIn('status', [SubscriptionStatus::Confirmed, SubscriptionStatus::Pending])
                    ->exists();

            if ($contact === null) {
                if (! $createIfMissing) {
                    return LinkMailingContactResult::skipped($email);
                }

                $contact = MailingContact::query()->create([
                    'email' => $email,
                    'user_id' => $user->id,
                    'name' => $user->name !== '' ? $user->name : null,
                ]);

                return LinkMailingContactResult::created($contact);
            }

            if ($contact->user_id !== null && (int) $contact->user_id !== (int) $user->id) {
                throw new RuntimeException('Mailing contact is already linked to another account.');
            }

            if ((int) $contact->user_id === (int) $user->id) {
                return LinkMailingContactResult::alreadyLinked($contact, $wasPreExisting, $hadSubscriptions);
            }

            // Clear any other contact still claiming this user_id (email-change edge cases).
            MailingContact::query()
                ->where('user_id', $user->id)
                ->whereKeyNot($contact->id)
                ->update(['user_id' => null]);

            $contact->update(['user_id' => $user->id]);

            if ($wasPreExisting && $hadSubscriptions) {
                $user->forceFill(['matched_mailing_notice_pending' => true])->save();
            }

            return LinkMailingContactResult::linked($contact->fresh() ?? $contact, $wasPreExisting, $hadSubscriptions);
        });
    }

    /**
     * Ensure an unlinked contact exists for a brand-new email (registration before verify).
     * Leaves pre-existing contacts untouched (no user_id claim).
     */
    public function ensureUnlinkedContactForEmail(string $email, ?string $name = null): MailingContact
    {
        $email = EmailAddress::normalize($email);

        $contact = MailingContact::query()->forNormalizedEmail($email)->first();

        if ($contact !== null) {
            return $contact;
        }

        return MailingContact::query()->create([
            'email' => $email,
            'name' => filled($name) ? $name : null,
            'user_id' => null,
        ]);
    }
}
