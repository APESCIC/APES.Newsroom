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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsentService
{
    public const WORDING_VERSION = 'v1';

    /**
     * @param  list<string>  $lists
     */
    public function signup(
        string $email,
        array $lists,
        string $source,
        ?User $user = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): MailingContact {
        $email = Str::lower(trim($email));
        $lists = $this->normalizeLists($lists);

        if ($lists === []) {
            throw new \InvalidArgumentException('At least one mailing list must be selected.');
        }

        return DB::transaction(function () use ($email, $lists, $source, $user, $ip, $userAgent) {
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
                    ['confirm_token_issued' => true],
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

    public function unsubscribe(
        string $email,
        ?MailingList $list = null,
        string $source = 'unsubscribe_link',
        ?string $ip = null,
        ?string $userAgent = null,
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
                [],
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
                ['reason' => 'unsubscribe_all'],
                $ip,
                $userAgent,
            );
        }
    }

    public function subscribeToNewsletter(
        string $email,
        Newsletter $newsletter,
        string $source,
        ?User $user = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): MailingListSubscription {
        if ($newsletter->isArchived()) {
            throw new \InvalidArgumentException('Archived newsletters cannot accept new subscriptions.');
        }

        if ($newsletter->legacy_list instanceof MailingList) {
            $contact = $this->signup($email, [$newsletter->legacy_list->value], $source, $user, $ip, $userAgent);

            return $contact->subscriptions()->where('newsletter_id', $newsletter->id)->firstOrFail();
        }

        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($email, $newsletter, $source, $user, $ip, $userAgent) {
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
                ['newsletter_id' => $newsletter->id, 'confirm_token_issued' => true],
                $ip,
                $userAgent,
            );

            $contact->notify(new ConfirmNewsletterNotification($newsletter, $this->confirmUrl($token)));

            return $subscription->fresh();
        });
    }

    public function unsubscribeFromNewsletter(
        string $email,
        Newsletter $newsletter,
        string $source = 'unsubscribe_link',
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        if ($newsletter->legacy_list instanceof MailingList) {
            $this->unsubscribe($email, $newsletter->legacy_list, $source, $ip, $userAgent);

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
            ['newsletter_id' => $newsletter->id],
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
    private function recordEvent(
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
