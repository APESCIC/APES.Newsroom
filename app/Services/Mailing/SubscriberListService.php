<?php

namespace App\Services\Mailing;

use App\Enums\SubscriptionStatus;
use App\Models\ConsentEvent;
use App\Models\MailingContact;
use App\Models\Newsletter;
use App\Models\Suppression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SubscriberListService
{
    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     email: string,
     *     name: string|null,
     *     signed_up_at: string,
     *     suppressed: bool,
     *     source: string|null,
     *     consent_action: string|null,
     *     subscriptions: list<array{newsletter: string, status: string, opted_out: bool}>
     * }>
     */
    public function paginate(
        ?string $search,
        ?int $newsletterId,
        ?string $source,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = MailingContact::query()
            ->mailingOnly()
            ->with(['subscriptions.newsletter'])
            ->orderByDesc('created_at');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('email', 'like', $term)
                    ->orWhere('email_normalized', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        if ($newsletterId !== null && $newsletterId > 0) {
            $query->whereHas(
                'subscriptions',
                fn (Builder $builder) => $builder->where('newsletter_id', $newsletterId),
            );
        }

        if (is_string($source) && trim($source) !== '') {
            $sourceValue = trim($source);
            $query->whereHas(
                'consentEvents',
                fn (Builder $builder) => $builder->where('source', $sourceValue),
            );
        }

        /** @var LengthAwarePaginator<int, MailingContact> $page */
        $page = $query->paginate($perPage)->withQueryString();

        $emails = $page->getCollection()->pluck('email_normalized')->all();
        $suppressions = Suppression::query()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->flip();

        $contactIds = $page->getCollection()->pluck('id')->all();
        $latestConsent = $this->latestConsentByContact($contactIds);

        return $page->through(function (MailingContact $contact) use ($suppressions, $latestConsent): array {
            $consent = $latestConsent[$contact->id] ?? null;
            $suppressed = $suppressions->has($contact->email_normalized);

            $subscriptions = $contact->subscriptions
                ->sortBy(fn ($sub) => $sub->newsletter?->name ?? $sub->list?->value ?? '')
                ->values()
                ->map(function ($sub) use ($suppressed): array {
                    $status = $sub->status instanceof SubscriptionStatus
                        ? $sub->status->value
                        : (string) $sub->status;
                    $optedOut = $suppressed || $status === SubscriptionStatus::Unsubscribed->value;

                    return [
                        'newsletter' => $sub->newsletter?->name
                            ?? $sub->list?->label()
                            ?? 'Unknown list',
                        'status' => $status,
                        'opted_out' => $optedOut,
                    ];
                })
                ->all();

            return [
                'id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->name,
                'signed_up_at' => $contact->created_at?->toIso8601String() ?? '',
                'suppressed' => $suppressed,
                'source' => $consent['source'] ?? null,
                'consent_action' => $consent['action'] ?? null,
                'subscriptions' => $subscriptions,
            ];
        });
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    public function newsletterOptions(): Collection
    {
        return Newsletter::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Newsletter $n) => ['id' => $n->id, 'name' => $n->name]);
    }

    /**
     * Distinct consent sources seen on mailing-only contacts.
     *
     * @return list<string>
     */
    public function sourceOptions(): array
    {
        return ConsentEvent::query()
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->whereHas('contact', fn (Builder $q) => $q->mailingOnly())
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $contactIds
     * @return array<int, array{source: string, action: string}>
     */
    private function latestConsentByContact(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $events = ConsentEvent::query()
            ->whereIn('mailing_contact_id', $contactIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['mailing_contact_id', 'source', 'action']);

        $latest = [];
        foreach ($events as $event) {
            $id = (int) $event->mailing_contact_id;
            if (isset($latest[$id])) {
                continue;
            }
            $latest[$id] = [
                'source' => (string) $event->source,
                'action' => $event->action instanceof \BackedEnum
                    ? $event->action->value
                    : (string) $event->action,
            ];
        }

        return $latest;
    }
}
