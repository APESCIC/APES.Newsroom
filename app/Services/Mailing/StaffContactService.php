<?php

namespace App\Services\Mailing;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Suppression;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\ConfirmNewsletterNotification;
use App\Services\Audit\AuditLogger;
use App\Support\EmailAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff changes to mailing contacts (#569). Staff can withdraw consent or
 * ask for it again, but never confirm it: every new or moved subscription
 * waits for the double opt-in link. Each change is audited and leaves a
 * consent event with source "staff".
 */
class StaffContactService
{
    public const SOURCE = 'staff';

    public function __construct(
        private readonly ConsentService $consent,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<string> the fields that changed
     */
    public function updateDetails(MailingContact $contact, ?string $name, string $email, string $reason, User $actor, Request $request): array
    {
        $newEmail = EmailAddress::normalize($email);
        $changed = [];
        $confirmations = [];

        DB::transaction(function () use ($contact, $name, $newEmail, $reason, $actor, $request, &$changed, &$confirmations) {
            $locked = MailingContact::query()->lockForUpdate()->findOrFail($contact->id);
            $oldEmail = (string) $locked->email_normalized;

            if ($name !== $locked->name) {
                $changed[] = 'name';
            }

            if ($newEmail !== $oldEmail) {
                $changed[] = 'email';
            }

            if ($changed === []) {
                return;
            }

            $locked->update(['name' => $name, 'email' => $newEmail]);

            if (in_array('email', $changed, true)) {
                $confirmations = $this->moveConsent($locked, $oldEmail, $reason, $actor, $request);
            }

            $this->audit->record($actor, 'mailing_contact.updated', $locked, [
                'reason' => $reason,
                'fields_changed' => $changed,
                'reconfirmation_requested' => count($confirmations),
            ], $request);
        });

        $this->sendConfirmations($contact->fresh(), $confirmations);

        return $changed;
    }

    public function unsubscribe(MailingContact $contact, MailingListSubscription $subscription, string $reason, User $actor, Request $request): void
    {
        $evidence = $this->evidence($actor, $reason);

        if ($subscription->newsletter) {
            $this->consent->unsubscribeFromNewsletter($contact->email, $subscription->newsletter, self::SOURCE, $request->ip(), $request->userAgent(), $evidence);
        } elseif ($subscription->list instanceof MailingList) {
            $this->consent->unsubscribe($contact->email, $subscription->list, self::SOURCE, $request->ip(), $request->userAgent(), $evidence);
        }

        $this->audit->record($actor, 'mailing_subscription.unsubscribed', $contact, [
            'reason' => $reason,
            'subscription_id' => $subscription->id,
            'newsletter_id' => $subscription->newsletter_id,
        ], $request);
    }

    /**
     * Sends the double opt-in email; the subscription stays pending until
     * the subscriber confirms it.
     *
     * @throws \InvalidArgumentException when the address is suppressed or the newsletter is archived
     */
    public function requestSubscription(MailingContact $contact, Newsletter $newsletter, string $reason, User $actor, Request $request): void
    {
        if ($this->consent->isSuppressed($contact->email)) {
            throw new \InvalidArgumentException('This address is suppressed. Remove the suppression before asking them to subscribe again.');
        }

        $this->consent->subscribeToNewsletter(
            $contact->email,
            $newsletter,
            self::SOURCE,
            $contact->user,
            $request->ip(),
            $request->userAgent(),
            $this->evidence($actor, $reason),
        );

        $this->audit->record($actor, 'mailing_subscription.resubscribe_requested', $contact, [
            'reason' => $reason,
            'newsletter_id' => $newsletter->id,
        ], $request);
    }

    public function suppress(MailingContact $contact, string $reason, User $actor, Request $request): void
    {
        Suppression::query()->firstOrCreate(
            ['email' => $contact->email_normalized],
            ['reason' => 'staff', 'notes' => null],
        );

        $this->consent->recordEvent($contact, null, ConsentAction::Suppressed, self::SOURCE, $this->evidence($actor, $reason), $request->ip(), $request->userAgent());
        $this->audit->record($actor, 'mailing_contact.suppressed', $contact, ['reason' => $reason], $request);
    }

    /**
     * Lifting a suppression does not resubscribe anyone; withdrawn
     * subscriptions stay withdrawn.
     */
    public function unsuppress(MailingContact $contact, string $reason, User $actor, Request $request): void
    {
        $deleted = Suppression::query()->where('email', $contact->email_normalized)->delete();

        if ($deleted === 0) {
            return;
        }

        $this->consent->recordEvent($contact, null, ConsentAction::Unsuppressed, self::SOURCE, $this->evidence($actor, $reason), $request->ip(), $request->userAgent());
        $this->audit->record($actor, 'mailing_contact.unsuppressed', $contact, ['reason' => $reason], $request);
    }

    /**
     * Subscriptions are deleted with the contact; consent events are kept as
     * evidence with the contact link cleared.
     */
    public function delete(MailingContact $contact, string $reason, bool $suppress, User $actor, Request $request): void
    {
        DB::transaction(function () use ($contact, $reason, $suppress, $actor, $request) {
            if ($suppress) {
                Suppression::query()->firstOrCreate(
                    ['email' => $contact->email_normalized],
                    ['reason' => 'staff', 'notes' => null],
                );
                $this->consent->recordEvent($contact, null, ConsentAction::Suppressed, self::SOURCE, $this->evidence($actor, $reason), $request->ip(), $request->userAgent());
            }

            $this->audit->record($actor, 'mailing_contact.deleted', $contact, [
                'reason' => $reason,
                'suppressed' => $suppress,
            ], $request);

            $contact->delete();
        });
    }

    /**
     * Active subscriptions go back to pending and need confirming from the
     * new address. A suppression on the old address follows the contact.
     *
     * @return list<array{0: MailingList|null, 1: Newsletter|null, 2: string}>
     */
    private function moveConsent(MailingContact $contact, string $oldEmail, string $reason, User $actor, Request $request): array
    {
        $newEmail = (string) $contact->email_normalized;
        $evidence = [...$this->evidence($actor, $reason), 'email_changed' => true];

        if (Suppression::query()->where('email', $oldEmail)->exists()) {
            Suppression::query()->firstOrCreate(['email' => $newEmail], ['reason' => 'staff_email_change', 'notes' => null]);
        }

        $this->consent->recordEvent($contact, null, ConsentAction::ContactUpdated, self::SOURCE, $evidence, $request->ip(), $request->userAgent());

        $suppressed = $this->consent->isSuppressed($newEmail);
        $confirmations = [];

        $active = $contact->subscriptions()
            ->with('newsletter')
            ->whereIn('status', [SubscriptionStatus::Confirmed, SubscriptionStatus::Pending])
            ->lockForUpdate()
            ->get();

        foreach ($active as $subscription) {
            if ($suppressed) {
                $subscription->update([
                    'status' => SubscriptionStatus::Unsubscribed,
                    'confirm_token' => null,
                    'unsubscribed_at' => now(),
                ]);
                $this->consent->recordEvent($contact, $subscription->list, ConsentAction::Unsubscribed, self::SOURCE, [...$evidence, 'newsletter_id' => $subscription->newsletter_id], $request->ip(), $request->userAgent());

                continue;
            }

            $token = Str::random(64);
            $subscription->update([
                'status' => SubscriptionStatus::Pending,
                'confirm_token' => $token,
                'confirmed_at' => null,
                'unsubscribed_at' => null,
            ]);
            $this->consent->recordEvent($contact, $subscription->list, ConsentAction::SignupRequested, self::SOURCE, [
                ...$evidence,
                'newsletter_id' => $subscription->newsletter_id,
                'confirm_token_issued' => true,
            ], $request->ip(), $request->userAgent());
            $confirmations[] = [$subscription->list, $subscription->newsletter, $token];
        }

        return $confirmations;
    }

    /**
     * @param  list<array{0: MailingList|null, 1: Newsletter|null, 2: string}>  $confirmations
     */
    private function sendConfirmations(?MailingContact $contact, array $confirmations): void
    {
        if (! $contact) {
            return;
        }

        foreach ($confirmations as [$list, $newsletter, $token]) {
            $url = url('/mailing/confirm/'.$token);

            if ($list instanceof MailingList) {
                $contact->notify(new ConfirmMailingListNotification($list, $url));
            } elseif ($newsletter) {
                $contact->notify(new ConfirmNewsletterNotification($newsletter, $url));
            }
        }
    }

    /**
     * @return array{actor_id: int, reason: string}
     */
    private function evidence(User $actor, string $reason): array
    {
        return ['actor_id' => $actor->id, 'reason' => $reason];
    }
}
