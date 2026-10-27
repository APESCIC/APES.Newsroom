<?php

namespace App\Services\Account;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Exceptions\EmailChangeFailed;
use App\Models\ConsentEvent;
use App\Models\EmailChangeRequest;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Suppression;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\ConfirmNewsletterNotification;
use App\Notifications\Security\EmailChangeRequestedNotification;
use App\Services\Mailing\ConsentService;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Account email changes apply only once the new address is confirmed; the
 * current address is told and can cancel until then. Links are signed
 * without an expiry so an old link shows a friendly page; the request's own
 * expires_at and single-use token decide whether it still works.
 */
class AccountEmailChangeService
{
    public const TTL_MINUTES = 60;

    /**
     * Save the name straight away and start a confirmation for a new email.
     * Returns null when the email is unchanged.
     *
     * @param  array{name: string, email: string}  $attributes
     */
    public function request(User $user, array $attributes): ?EmailChangeRequest
    {
        return DB::transaction(function () use ($user, $attributes) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->update(['name' => $attributes['name']]);

            $newEmail = EmailAddress::normalize($attributes['email']);

            if ($newEmail === EmailAddress::normalize($lockedUser->email)) {
                return null;
            }

            $lockedUser->emailChangeRequests()->pending()->update(['cancelled_at' => now()]);

            $token = Str::random(64);
            $change = $lockedUser->emailChangeRequests()->create([
                'new_email' => $newEmail,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            DB::afterCommit(function () use ($lockedUser, $change, $token) {
                $this->sendConfirmation($change, $token);
                $lockedUser->notify(new EmailChangeRequestedNotification(
                    EmailAddress::mask($change->new_email),
                    URL::signedRoute('account.email.cancel', ['emailChange' => $change->id]),
                ));
            });

            return $change;
        });
    }

    /**
     * Send a fresh confirmation link for a pending change.
     */
    public function resend(EmailChangeRequest $change): void
    {
        $token = Str::random(64);
        $change->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $this->sendConfirmation($change, $token);
    }

    public function cancel(EmailChangeRequest $change): bool
    {
        return EmailChangeRequest::query()
            ->whereKey($change->id)
            ->pending()
            ->update(['cancelled_at' => now()]) > 0;
    }

    /**
     * Apply a pending change: move the account and its mailing consent to
     * the new address. Existing list subscriptions are withdrawn from the
     * old address and must be confirmed again from the new one.
     *
     * @throws EmailChangeFailed
     */
    public function confirm(EmailChangeRequest $change, string $token, ?string $ip = null, ?string $userAgent = null): User
    {
        return DB::transaction(function () use ($change, $token, $ip, $userAgent) {
            $lockedChange = EmailChangeRequest::query()->lockForUpdate()->findOrFail($change->id);

            if (! hash_equals($lockedChange->token_hash, hash('sha256', $token))) {
                throw new EmailChangeFailed('This confirmation link is not valid. Use the latest link we sent.');
            }

            if (! $lockedChange->isPending()) {
                throw new EmailChangeFailed('This email change has expired or was cancelled. You can request it again from your account.');
            }

            $lockedUser = User::query()->lockForUpdate()->findOrFail($lockedChange->user_id);
            $oldEmail = EmailAddress::normalize($lockedUser->email);
            $newEmail = EmailAddress::normalize($lockedChange->new_email);

            if ($lockedUser->auth_provider === 'cloudron_oidc') {
                throw new EmailChangeFailed('This account\'s email address is managed by APES staff sign-in.');
            }

            if (User::query()->where('email', $newEmail)->whereKeyNot($lockedUser->id)->exists()) {
                throw new EmailChangeFailed('That email address is now used by another account.');
            }

            $confirmations = $this->transferMailingConsent($lockedUser, $oldEmail, $newEmail, $ip, $userAgent);

            $lockedUser->forceFill([
                'email' => $newEmail,
                'email_verified_at' => now(),
            ])->save();

            $lockedChange->update(['confirmed_at' => now()]);

            DB::afterCommit(function () use ($confirmations) {
                foreach ($confirmations as [$contactId, $list, $newsletterId, $confirmToken]) {
                    $contact = MailingContact::query()->find($contactId);
                    $url = url('/mailing/confirm/'.$confirmToken);

                    if ($list instanceof MailingList) {
                        $contact?->notify(new ConfirmMailingListNotification($list, $url));
                    } elseif ($newsletter = Newsletter::query()->find($newsletterId)) {
                        $contact?->notify(new ConfirmNewsletterNotification($newsletter, $url));
                    }
                }
            });

            return $lockedUser->fresh();
        });
    }

    private function sendConfirmation(EmailChangeRequest $change, string $token): void
    {
        Notification::route('mail', $change->new_email)->notify(new ConfirmEmailChangeNotification(
            URL::signedRoute('account.email.confirm', [
                'emailChange' => $change->id,
                'token' => $token,
            ]),
            self::TTL_MINUTES,
        ));
    }

    /**
     * Subscriptions are keyed by legacy list when they have one, otherwise
     * by newsletter, so newsletter-only subscriptions transfer too (#573).
     *
     * @return list<array{0: int, 1: MailingList|null, 2: int|null, 3: string}> confirmation emails to send after commit
     */
    private function transferMailingConsent(User $user, string $oldEmail, string $newEmail, ?string $ip, ?string $userAgent): array
    {
        $oldContact = MailingContact::query()
            ->forNormalizedEmail($oldEmail)
            ->lockForUpdate()
            ->first();

        if (! $oldContact) {
            return [];
        }

        $oldSuppressed = Suppression::query()
            ->where('email', $oldEmail)
            ->lockForUpdate()
            ->exists();
        $activeLists = [];

        foreach ($oldContact->subscriptions()->lockForUpdate()->get() as $subscription) {
            if (! in_array($subscription->status, [SubscriptionStatus::Confirmed, SubscriptionStatus::Pending], true)) {
                continue;
            }

            if (! $oldSuppressed && ($subscription->list !== null || $subscription->newsletter_id !== null)) {
                $key = $subscription->list !== null ? 'list:'.$subscription->list->value : 'newsletter:'.$subscription->newsletter_id;
                $activeLists[$key] = ['list' => $subscription->list, 'newsletter_id' => $subscription->newsletter_id];
            }

            $subscription->update([
                'status' => SubscriptionStatus::Unsubscribed,
                'confirm_token' => null,
                'unsubscribed_at' => now(),
            ]);

            $this->recordEvent(
                $oldContact,
                $subscription->list,
                ConsentAction::Unsubscribed,
                ['account_email_changed_to' => $newEmail, 'newsletter_id' => $subscription->newsletter_id],
                $ip,
                $userAgent,
            );
        }

        Suppression::query()->firstOrCreate(
            ['email' => $oldEmail],
            ['reason' => 'account_email_change'],
        );
        $this->recordEvent(
            $oldContact,
            null,
            ConsentAction::Suppressed,
            ['reason' => 'account_email_change'],
            $ip,
            $userAgent,
        );
        $oldContact->update(['user_id' => null]);

        $newContact = MailingContact::query()
            ->forNormalizedEmail($newEmail)
            ->lockForUpdate()
            ->first();

        if (! $newContact) {
            $newContact = MailingContact::query()->create([
                'email' => $newEmail,
                'user_id' => $user->id,
            ]);
        } elseif ($newContact->user_id !== null && (int) $newContact->user_id !== (int) $user->id) {
            // New address already claimed — clear the other link so unique user_id holds.
            $newContact->update(['user_id' => null]);
            $newContact->update(['user_id' => $user->id]);
        } elseif ($newContact->user_id !== $user->id) {
            $newContact->update(['user_id' => $user->id]);
        }

        $newSuppressed = Suppression::query()
            ->where('email', $newEmail)
            ->lockForUpdate()
            ->exists();

        if ($newSuppressed) {
            return [];
        }

        $confirmations = [];

        foreach ($activeLists as ['list' => $list, 'newsletter_id' => $newsletterId]) {
            $subscription = MailingListSubscription::query()
                ->where('mailing_contact_id', $newContact->id)
                ->when(
                    $list !== null,
                    fn ($query) => $query->where('list', $list->value),
                    fn ($query) => $query->where('newsletter_id', $newsletterId),
                )
                ->lockForUpdate()
                ->first();

            if ($subscription?->status === SubscriptionStatus::Unsubscribed) {
                continue;
            }

            $token = Str::random(64);
            $subscription ??= new MailingListSubscription([
                'mailing_contact_id' => $newContact->id,
                'list' => $list,
                'newsletter_id' => $newsletterId,
            ]);
            $subscription->fill([
                'status' => SubscriptionStatus::Pending,
                'confirm_token' => $token,
                'confirmed_at' => null,
                'unsubscribed_at' => null,
            ])->save();

            $this->recordEvent(
                $newContact,
                $list,
                ConsentAction::SignupRequested,
                ['confirm_token_issued' => true, 'account_email_changed_from' => $oldEmail, 'newsletter_id' => $newsletterId],
                $ip,
                $userAgent,
            );
            $confirmations[] = [$newContact->id, $list, $newsletterId, $token];
        }

        return $confirmations;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function recordEvent(
        MailingContact $contact,
        ?MailingList $list,
        ConsentAction $action,
        array $evidence,
        ?string $ip,
        ?string $userAgent,
    ): void {
        ConsentEvent::query()->create([
            'mailing_contact_id' => $contact->id,
            'email' => $contact->email,
            'list' => $list,
            'action' => $action,
            'source' => 'account_email_change',
            'wording_version' => ConsentService::WORDING_VERSION,
            'evidence' => $evidence,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }
}
