<?php

namespace App\Services\Mailing;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\ConsentEvent;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Models\Suppression;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\ConfirmNewsletterNotification;
use App\Support\EmailAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsentService
{
    public const WORDING_VERSION = 'v1';

    /** The choice wording shown in mailing preferences, kept as consent evidence. */
    public const TRACKING_WORDING = 'Tell us when you open our emails (a tracking pixel). Let us count clicks on links in our emails.';

    /**
     * @param  list<string>  $lists
     * @param  array<string, mixed>  $evidence  extra evidence recorded on each consent event
     */
    public function signup(
        string $email,
        array $lists,
        string $source,
        ?User $user = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $evidence = [],
    ): MailingContact {
        $email = Str::lower(trim($email));
        $lists = $this->normalizeLists($lists);

        if ($lists === []) {
            throw new \InvalidArgumentException('At least one mailing list must be selected.');
        }

        return DB::transaction(function () use ($email, $lists, $source, $user, $ip, $userAgent, $evidence) {
            $contact = MailingContact::query()->firstOrCreate(
                ['email' => $email],
                ['user_id' => $user?->id],
            );

            if ($user && $contact->user_id === null) {
                $contact->update(['user_id' => $user->id]);
            }

            foreach ($lists as $list) {
                $subscription = MailingListSubscription::query()->firstOrNew([
                    'mailing_contact_id' => $contact->id,
                    'list' => $list->value,
                ]);

                if ($subscription->status === SubscriptionStatus::Confirmed) {
                    $this->attachLegacyNewsletter($subscription, $list);
                    $subscription->save();

                    continue;
                }

                $token = Str::random(64);
                $subscription->fill([
                    'status' => SubscriptionStatus::Pending,
                    'confirm_token' => $token,
                    'confirmed_at' => null,
                    'unsubscribed_at' => null,
                ]);
                $this->attachLegacyNewsletter($subscription, $list);
                $subscription->save();

                $this->recordEvent(
                    $contact,
                    $list,
                    ConsentAction::SignupRequested,
                    $source,
                    ['confirm_token_issued' => true, ...$evidence],
                    $ip,
                    $userAgent,
                );

                $contact->notify(new ConfirmMailingListNotification(
                    $list,
                    $this->confirmUrl($token),
                ));
            }

            return $contact->fresh(['subscriptions']);
        });
    }

    public function confirm(string $token, ?string $ip = null, ?string $userAgent = null): MailingListSubscription
    {
        $subscription = MailingListSubscription::query()
            ->where('confirm_token', $token)
            ->where('status', SubscriptionStatus::Pending)
            ->firstOrFail();

        $subscription->update([
            'status' => SubscriptionStatus::Confirmed,
            'confirm_token' => null,
            'confirmed_at' => now(),
            'unsubscribed_at' => null,
        ]);

        $this->recordEvent(
            $subscription->contact,
            $subscription->list,
            ConsentAction::Confirmed,
            'double_opt_in',
            ['verified' => true],
            $ip,
            $userAgent,
        );

        return $subscription->fresh(['contact']);
    }

    /**
     * @param  array<string, mixed>  $evidence  extra evidence recorded on each consent event
     */
    public function unsubscribe(
        string $email,
        ?MailingList $list = null,
        string $source = 'unsubscribe_link',
        ?string $ip = null,
        ?string $userAgent = null,
        array $evidence = [],
    ): void {
        $email = Str::lower(trim($email));
        $contact = MailingContact::query()->where('email', $email)->first();

        if (! $contact) {
            Suppression::query()->firstOrCreate(
                ['email' => $email],
                ['reason' => 'unsubscribe_without_contact'],
            );

            return;
        }

        $query = $contact->subscriptions();
        if ($list) {
            $query->where('list', $list->value);
        }

        /** @var Collection<int, MailingListSubscription> $subscriptions */
        $subscriptions = $query->get();

        foreach ($subscriptions as $subscription) {
            if ($subscription->status === SubscriptionStatus::Unsubscribed) {
                continue;
            }

            $subscription->update([
                'status' => SubscriptionStatus::Unsubscribed,
                'confirm_token' => null,
                'unsubscribed_at' => now(),
            ]);

            $this->recordEvent(
                $contact,
                $subscription->list,
                ConsentAction::Unsubscribed,
                $source,
                $evidence,
                $ip,
                $userAgent,
            );
        }

        if ($list === null) {
            Suppression::query()->firstOrCreate(
                ['email' => $email],
                ['reason' => 'unsubscribe_all'],
            );

            $this->recordEvent(
                $contact,
                null,
                ConsentAction::Suppressed,
                $source,
                ['reason' => 'unsubscribe_all', ...$evidence],
                $ip,
                $userAgent,
            );
        }
    }

    /**
     * Newsletters ticked on the registration form are held pending and
     * confirmed by the account email verification link, so the member gets
     * one email rather than one confirmation per newsletter.
     *
     * @param  list<int>  $newsletterIds
     * @return list<int>
     */
    public function requestAtRegistration(User $user, array $newsletterIds, ?string $ip = null, ?string $userAgent = null): array
    {
        $newsletters = Newsletter::query()
            ->active()
            ->where('subscribe_on_signup', true)
            ->whereIn('id', array_map('intval', $newsletterIds))
            ->get();

        if ($newsletters->isEmpty()) {
            return [];
        }

        $email = EmailAddress::normalize((string) $user->email);

        return DB::transaction(function () use ($newsletters, $user, $email, $ip, $userAgent) {
            // Never claim user_id before verification (#506). Opt-outs / suppressions /
            // confirmed rows win over registration ticks (#506 / #508).
            $contact = MailingContact::query()->forNormalizedEmail($email)->first()
                ?? MailingContact::query()->create([
                    'email' => $email,
                    'name' => $user->name !== '' ? $user->name : null,
                    'user_id' => null,
                ]);

            if (Suppression::query()->where('email', $email)->exists()) {
                return [];
            }

            $requested = [];

            foreach ($newsletters as $newsletter) {
                $legacy = $newsletter->legacy_list;
                $subscription = MailingListSubscription::query()->firstOrNew(
                    $legacy instanceof MailingList
                        ? ['mailing_contact_id' => $contact->id, 'list' => $legacy->value]
                        : ['mailing_contact_id' => $contact->id, 'newsletter_id' => $newsletter->id],
                );

                if ($subscription->exists) {
                    if (in_array($subscription->status, [
                        SubscriptionStatus::Confirmed,
                        SubscriptionStatus::Unsubscribed,
                        SubscriptionStatus::Pending,
                    ], true)) {
                        continue;
                    }
                }

                $subscription->fill([
                    'newsletter_id' => $newsletter->id,
                    'list' => $legacy?->value,
                    'status' => SubscriptionStatus::Pending,
                    'confirm_token' => Str::random(64),
                    'confirmed_at' => null,
                    'unsubscribed_at' => null,
                ])->save();

                $this->recordEvent(
                    $contact,
                    $legacy,
                    ConsentAction::SignupRequested,
                    'registration',
                    ['newsletter_id' => $newsletter->id, 'confirm_via' => 'email_verification'],
                    $ip,
                    $userAgent,
                );
                $requested[] = $newsletter->id;
            }

            return $requested;
        });
    }

    /**
     * Confirm the registration requests once the account email is verified.
     */
    public function confirmRegistrationRequests(User $user): int
    {
        $contact = MailingContact::query()
            ->forNormalizedEmail((string) $user->email)
            ->first();
        if (! $contact) {
            return 0;
        }

        $requested = ConsentEvent::query()
            ->where('mailing_contact_id', $contact->id)
            ->where('source', 'registration')
            ->where('action', ConsentAction::SignupRequested)
            ->get()
            ->map(fn (ConsentEvent $event) => (int) ($event->evidence['newsletter_id'] ?? 0))
            ->filter()
            ->unique()
            ->all();

        if ($requested === []) {
            return 0;
        }

        $pending = $contact->subscriptions()
            ->whereIn('newsletter_id', $requested)
            ->where('status', SubscriptionStatus::Pending)
            ->get();

        foreach ($pending as $subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Confirmed,
                'confirm_token' => null,
                'confirmed_at' => now(),
            ]);

            $this->recordEvent(
                $contact,
                $subscription->list,
                ConsentAction::Confirmed,
                'registration_email_verified',
                ['newsletter_id' => $subscription->newsletter_id, 'verified' => true],
            );
        }

        return $pending->count();
    }

    /**
     * @param  array<string, mixed>  $evidence  extra evidence recorded on the consent event
     */
    public function subscribeToNewsletter(
        string $email,
        Newsletter $newsletter,
        string $source,
        ?User $user = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $evidence = [],
    ): MailingListSubscription {
        if ($newsletter->isArchived()) {
            throw new \InvalidArgumentException('Archived newsletters cannot accept new subscriptions.');
        }

        if ($newsletter->legacy_list instanceof MailingList) {
            $contact = $this->signup($email, [$newsletter->legacy_list->value], $source, $user, $ip, $userAgent, $evidence);

            return $contact->subscriptions()->where('newsletter_id', $newsletter->id)->firstOrFail();
        }

        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($email, $newsletter, $source, $user, $ip, $userAgent, $evidence) {
            $contact = MailingContact::query()->firstOrCreate(
                ['email' => $email],
                ['user_id' => $user?->id],
            );

            $subscription = MailingListSubscription::query()->firstOrNew([
                'mailing_contact_id' => $contact->id,
                'newsletter_id' => $newsletter->id,
            ]);

            if ($subscription->status === SubscriptionStatus::Confirmed) {
                return $subscription;
            }

            $token = Str::random(64);
            $subscription->fill([
                'list' => null,
                'status' => SubscriptionStatus::Pending,
                'confirm_token' => $token,
                'confirmed_at' => null,
                'unsubscribed_at' => null,
            ]);
            $subscription->save();

            $this->recordEvent(
                $contact,
                null,
                ConsentAction::SignupRequested,
                $source,
                ['newsletter_id' => $newsletter->id, 'confirm_token_issued' => true, ...$evidence],
                $ip,
                $userAgent,
            );

            $contact->notify(new ConfirmNewsletterNotification($newsletter, $this->confirmUrl($token)));

            return $subscription->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $evidence  extra evidence recorded on the consent event
     */
    public function unsubscribeFromNewsletter(
        string $email,
        Newsletter $newsletter,
        string $source = 'unsubscribe_link',
        ?string $ip = null,
        ?string $userAgent = null,
        array $evidence = [],
    ): void {
        if ($newsletter->legacy_list instanceof MailingList) {
            $this->unsubscribe($email, $newsletter->legacy_list, $source, $ip, $userAgent, $evidence);

            return;
        }

        $email = Str::lower(trim($email));
        $contact = MailingContact::query()->where('email', $email)->first();

        if (! $contact) {
            return;
        }

        $subscription = $contact->subscriptions()->where('newsletter_id', $newsletter->id)->first();

        if (! $subscription || $subscription->status === SubscriptionStatus::Unsubscribed) {
            return;
        }

        $subscription->update([
            'status' => SubscriptionStatus::Unsubscribed,
            'confirm_token' => null,
            'unsubscribed_at' => now(),
        ]);

        $this->recordEvent(
            $contact,
            null,
            ConsentAction::Unsubscribed,
            $source,
            ['newsletter_id' => $newsletter->id, ...$evidence],
            $ip,
            $userAgent,
        );
    }

    /**
     * @param  list<int>  $selectedIds
     */
    public function syncNewsletterChoices(
        string $email,
        array $selectedIds,
        ?User $user = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $selected = array_map('intval', $selectedIds);
        $newsletters = Newsletter::query()->active()->whereNull('legacy_list')->get();

        foreach ($newsletters as $newsletter) {
            $subscription = MailingListSubscription::query()
                ->where('newsletter_id', $newsletter->id)
                ->whereHas('contact', fn ($q) => $q->where('email', Str::lower(trim($email))))
                ->first();
            $status = $subscription?->status;
            $wants = in_array($newsletter->id, $selected, true);

            if ($wants && $status !== SubscriptionStatus::Confirmed && $status !== SubscriptionStatus::Pending) {
                $this->subscribeToNewsletter($email, $newsletter, 'account_preferences', $user, $ip, $userAgent);
            } elseif (! $wants && in_array($status, [SubscriptionStatus::Confirmed, SubscriptionStatus::Pending], true)) {
                $this->unsubscribeFromNewsletter($email, $newsletter, 'account_preferences', $ip, $userAgent);
            }
        }
    }

    /**
     * @return list<array{id: int, name: string, description: string|null, status: string|null}>
     */
    public function extraNewsletterStateForEmail(string $email): array
    {
        $contact = MailingContact::query()->where('email', Str::lower(trim($email)))->first();
        $byNewsletter = $contact
            ? $contact->subscriptions->keyBy('newsletter_id')
            : collect();

        return Newsletter::query()->active()->whereNull('legacy_list')->orderBy('name')->get()
            ->map(function (Newsletter $newsletter) use ($byNewsletter) {
                /** @var MailingListSubscription|null $sub */
                $sub = $byNewsletter->get($newsletter->id);

                return [
                    'id' => $newsletter->id,
                    'name' => $newsletter->name,
                    'description' => $newsletter->description,
                    'status' => $sub?->status->value,
                ];
            })
            ->all();
    }

    /**
     * Sync account preference centre choices (checked = request DOI or keep confirmed).
     *
     * @param  list<string>  $selectedLists
     */
    public function syncAccountPreferences(
        User $user,
        array $selectedLists,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $email = Str::lower($user->email);
        $selected = collect($this->normalizeLists($selectedLists))->map->value->all();

        $contact = MailingContact::query()->firstOrCreate(
            ['email' => $email],
            ['user_id' => $user->id],
        );

        if ($contact->user_id !== $user->id) {
            $contact->update(['user_id' => $user->id]);
        }

        foreach (MailingList::cases() as $list) {
            $subscription = MailingListSubscription::query()->firstOrNew([
                'mailing_contact_id' => $contact->id,
                'list' => $list->value,
            ]);

            $wants = in_array($list->value, $selected, true);

            if ($wants) {
                if ($subscription->exists && $subscription->status === SubscriptionStatus::Confirmed) {
                    continue;
                }

                if ($subscription->exists && $subscription->status === SubscriptionStatus::Pending) {
                    continue;
                }

                $token = Str::random(64);
                $subscription->fill([
                    'status' => SubscriptionStatus::Pending,
                    'confirm_token' => $token,
                    'confirmed_at' => null,
                    'unsubscribed_at' => null,
                ]);
                $this->attachLegacyNewsletter($subscription, $list);
                $subscription->save();

                $this->recordEvent(
                    $contact,
                    $list,
                    ConsentAction::SignupRequested,
                    'account_preferences',
                    ['confirm_token_issued' => true],
                    $ip,
                    $userAgent,
                );

                $contact->notify(new ConfirmMailingListNotification(
                    $list,
                    $this->confirmUrl($token),
                ));
            } elseif ($subscription->exists && $subscription->status !== SubscriptionStatus::Unsubscribed) {
                $subscription->update([
                    'status' => SubscriptionStatus::Unsubscribed,
                    'confirm_token' => null,
                    'unsubscribed_at' => now(),
                ]);

                $this->recordEvent(
                    $contact,
                    $list,
                    ConsentAction::Unsubscribed,
                    'account_preferences',
                    [],
                    $ip,
                    $userAgent,
                );
            }
        }
    }

    /**
     * Stop all mail to an address because of a delivery event (bounce,
     * complaint, provider unsubscribe, repeated temporary failures).
     * Returns false when it was already suppressed; the first reason stays.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function suppress(string $email, string $reason, string $source, ?string $notes = null, array $evidence = []): bool
    {
        $email = Str::lower(trim($email));
        $suppression = Suppression::query()->firstOrCreate(['email' => $email], [
            'reason' => $reason,
            'source' => $source,
            'notes' => $notes !== null ? Str::limit($notes, 1000) : null,
        ]);

        if (! $suppression->wasRecentlyCreated) {
            return false;
        }

        $contact = MailingContact::query()->where('email', $email)->first();
        if ($contact !== null) {
            $this->recordEvent($contact, null, ConsentAction::Suppressed, $source, ['reason' => $reason, ...$evidence]);
        }

        return true;
    }

    /**
     * The reader's tracking choices: open tracking is opt-in, click tracking
     * is on unless they opt out. Null when the address has no contact yet.
     *
     * @return array{opens: bool, clicks: bool}|null
     */
    public function trackingPreferences(string $email): ?array
    {
        $contact = MailingContact::query()->where('email', Str::lower(trim($email)))->first();

        return $contact === null ? null : [
            'opens' => $contact->open_tracking_consented_at !== null,
            'clicks' => $contact->click_tracking_opted_out_at === null,
        ];
    }

    /**
     * Save the reader's tracking choices, recording each change in their
     * consent history.
     */
    public function setTrackingPreferences(string $email, bool $opens, bool $clicks, string $source, ?string $ip = null, ?string $userAgent = null): void
    {
        $contact = MailingContact::query()->where('email', Str::lower(trim($email)))->first();
        if ($contact === null) {
            return;
        }

        $wasOpens = $contact->open_tracking_consented_at !== null;
        $wasClicks = $contact->click_tracking_opted_out_at === null;
        if ($wasOpens === $opens && $wasClicks === $clicks) {
            return;
        }

        $contact->forceFill([
            'open_tracking_consented_at' => $opens ? ($contact->open_tracking_consented_at ?? now()) : null,
            'click_tracking_opted_out_at' => $clicks ? null : ($contact->click_tracking_opted_out_at ?? now()),
        ])->save();

        $evidence = ['wording' => self::TRACKING_WORDING];
        if ($wasOpens !== $opens) {
            $this->recordEvent($contact, null, $opens ? ConsentAction::OpenTrackingConsented : ConsentAction::OpenTrackingWithdrawn, $source, $evidence, $ip, $userAgent);
        }
        if ($wasClicks !== $clicks) {
            $this->recordEvent($contact, null, $clicks ? ConsentAction::ClickTrackingOptedIn : ConsentAction::ClickTrackingOptedOut, $source, $evidence, $ip, $userAgent);
        }
    }

    public function isSuppressed(string $email): bool
    {
        return Suppression::query()->where('email', Str::lower(trim($email)))->exists();
    }

    public function hasConfirmedConsent(string $email, MailingList $list): bool
    {
        if ($this->isSuppressed($email)) {
            return false;
        }

        $contact = MailingContact::query()->where('email', Str::lower(trim($email)))->first();

        if (! $contact) {
            return false;
        }

        return $contact->subscriptions()
            ->where('list', $list->value)
            ->where('status', SubscriptionStatus::Confirmed)
            ->exists();
    }

    /**
     * Whether the address is confirmed (and not suppressed) on an active
     * newsletter: through its legacy list, or its own subscriptions.
     */
    public function hasConfirmedNewsletterConsent(string $email, Newsletter $newsletter): bool
    {
        if ($newsletter->isArchived()) {
            return false;
        }

        if ($newsletter->legacy_list instanceof MailingList) {
            return $this->hasConfirmedConsent($email, $newsletter->legacy_list);
        }

        if ($this->isSuppressed($email)) {
            return false;
        }

        $contact = MailingContact::query()->where('email', Str::lower(trim($email)))->first();

        return $contact !== null && $contact->subscriptions()
            ->where('newsletter_id', $newsletter->id)
            ->where('status', SubscriptionStatus::Confirmed)
            ->exists();
    }

    /**
     * Confirmed emails for any of the given lists, de-duplicated.
     *
     * @param  list<string|MailingList>  $lists
     * @return list<string>
     */
    public function confirmedEmailsForLists(array $lists): array
    {
        $normalized = $this->normalizeLists($lists);
        if ($normalized === []) {
            return [];
        }

        $listValues = array_map(fn (MailingList $l) => $l->value, $normalized);

        $emails = MailingListSubscription::query()
            ->whereIn('list', $listValues)
            ->where('status', SubscriptionStatus::Confirmed)
            ->with('contact')
            ->get()
            ->pluck('contact.email')
            ->filter()
            ->map(fn (string $email) => Str::lower($email))
            ->unique()
            ->values();

        $suppressed = Suppression::query()->pluck('email')->map(fn (string $e) => Str::lower($e))->all();

        return $emails->reject(fn (string $email) => in_array($email, $suppressed, true))->values()->all();
    }

    /**
     * Confirmed, non-suppressed emails on one newsletter.
     *
     * @return list<string>
     */
    public function confirmedEmailsForNewsletter(Newsletter $newsletter): array
    {
        if ($newsletter->isArchived()) {
            return [];
        }

        if ($newsletter->legacy_list instanceof MailingList) {
            return $this->confirmedEmailsForLists([$newsletter->legacy_list->value]);
        }

        $emails = MailingListSubscription::query()
            ->where('newsletter_id', $newsletter->id)
            ->where('status', SubscriptionStatus::Confirmed)
            ->with('contact')
            ->get()
            ->pluck('contact.email')
            ->filter()
            ->map(fn (string $email) => Str::lower($email))
            ->unique()
            ->values();

        $suppressed = Suppression::query()->pluck('email')->map(fn (string $e) => Str::lower($e))->all();

        return $emails->reject(fn (string $email) => in_array($email, $suppressed, true))->values()->all();
    }

    /**
     * Emails confirmed on both newsletters in the segment. Empty is safe.
     *
     * @return list<string>
     */
    public function confirmedEmailsForSegment(NewsletterSegment $segment): array
    {
        $segment->loadMissing('newsletter', 'alsoNewsletter');
        $primary = $this->confirmedEmailsForNewsletter($segment->newsletter);
        $also = $this->confirmedEmailsForNewsletter($segment->alsoNewsletter);

        return array_values(array_intersect($primary, $also));
    }

    /**
     * @return array<string, array{list: string, label: string, status: string|null, purpose: string}>
     */
    public function preferenceStateForEmail(string $email): array
    {
        $contact = MailingContact::query()->where('email', Str::lower(trim($email)))->first();
        $subscriptions = $contact
            ? $contact->subscriptions->filter(fn (MailingListSubscription $s) => $s->list !== null)
                ->keyBy(fn (MailingListSubscription $s) => $s->list->value)
            : collect();

        $state = [];
        foreach (MailingList::cases() as $list) {
            /** @var MailingListSubscription|null $sub */
            $sub = $subscriptions->get($list->value);
            $state[$list->value] = [
                'list' => $list->value,
                'label' => $list->label(),
                'purpose' => $list->purpose(),
                'status' => $sub?->status->value,
            ];
        }

        return $state;
    }

    private function attachLegacyNewsletter(MailingListSubscription $subscription, MailingList $list): void
    {
        if ($subscription->newsletter_id !== null) {
            return;
        }

        $newsletterId = Newsletter::query()->where('legacy_list', $list->value)->value('id');

        if ($newsletterId !== null) {
            $subscription->newsletter_id = $newsletterId;
        }
    }

    private function confirmUrl(string $token): string
    {
        return url('/mailing/confirm/'.$token);
    }

    /**
     * @param  list<string|MailingList>  $lists
     * @return list<MailingList>
     */
    private function normalizeLists(array $lists): array
    {
        $out = [];
        foreach ($lists as $list) {
            if ($list instanceof MailingList) {
                $out[$list->value] = $list;

                continue;
            }

            $enum = MailingList::tryFrom((string) $list);
            if ($enum) {
                $out[$enum->value] = $enum;
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function recordEvent(
        ?MailingContact $contact,
        ?MailingList $list,
        ConsentAction $action,
        string $source,
        array $evidence = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        ConsentEvent::create([
            'mailing_contact_id' => $contact?->id,
            'email' => $contact?->email ?? '',
            'list' => $list?->value,
            'action' => $action,
            'source' => $source,
            'wording_version' => self::WORDING_VERSION,
            'evidence' => $evidence === [] ? null : $evidence,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }
}
