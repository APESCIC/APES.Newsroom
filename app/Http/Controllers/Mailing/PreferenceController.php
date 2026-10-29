<?php

namespace App\Http\Controllers\Mailing;

use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Services\Mailing\ConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PreferenceController extends Controller
{
    public function __construct(private readonly ConsentService $consent) {}

    public function showSigned(Request $request): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $email = strtolower((string) $request->query('email'));

        return Inertia::render('Mailing/Preferences', [
            'email' => $email,
            'lists' => array_values($this->consent->preferenceStateForEmail($email)),
            'newsletters' => $this->consent->extraNewsletterStateForEmail($email),
            'tracking' => $this->consent->trackingPreferences($email),
            'signed' => true,
        ]);
    }

    public function showAccount(Request $request): Response
    {
        $user = $request->user();
        $email = strtolower($user->email);

        return Inertia::render('Mailing/Preferences', [
            'email' => $email,
            'lists' => array_values($this->consent->preferenceStateForEmail($email)),
            'newsletters' => $this->consent->extraNewsletterStateForEmail($email),
            'tracking' => $this->consent->trackingPreferences($email),
            'signed' => false,
            'matched_mailing_notice' => $this->matchedMailingNotice($user),
        ]);
    }

    /**
     * @return array{newsletters: list<string>}|null
     */
    private function matchedMailingNotice($user): ?array
    {
        if (! $user->matched_mailing_notice_pending) {
            return null;
        }

        $contact = MailingContact::query()->forNormalizedEmail((string) $user->email)->first();
        if (! $contact) {
            return null;
        }

        $names = $contact->subscriptions()
            ->with('newsletter')
            ->whereIn('status', [SubscriptionStatus::Confirmed, SubscriptionStatus::Pending])
            ->get()
            ->map(fn (MailingListSubscription $sub) => $sub->newsletter?->name ?? $sub->list?->label() ?? 'Newsletter')
            ->unique()
            ->values()
            ->all();

        return $names === [] ? null : ['newsletters' => $names];
    }

    public function updateSigned(Request $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $email = strtolower((string) $request->query('email'));
        $validated = $request->validate([
            'lists' => ['array'],
            'lists.*' => [Rule::enum(MailingList::class)],
            'newsletter_ids' => ['array'],
            'newsletter_ids.*' => ['integer', 'exists:newsletters,id'],
            'track_opens' => ['sometimes', 'boolean'],
            'track_clicks' => ['sometimes', 'boolean'],
        ]);

        $selected = $validated['lists'] ?? [];
        $current = $this->consent->preferenceStateForEmail($email);

        foreach (MailingList::cases() as $list) {
            $wants = in_array($list->value, $selected, true);
            $status = $current[$list->value]['status'] ?? null;

            if ($wants && $status !== 'confirmed' && $status !== 'pending') {
                $this->consent->signup(
                    email: $email,
                    lists: [$list->value],
                    source: 'signed_preferences',
                    ip: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            } elseif (! $wants && in_array($status, ['confirmed', 'pending'], true)) {
                $this->consent->unsubscribe(
                    email: $email,
                    list: $list,
                    source: 'signed_preferences',
                    ip: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }
        }

        $this->consent->syncNewsletterChoices(
            $email,
            $validated['newsletter_ids'] ?? [],
            null,
            $request->ip(),
            $request->userAgent(),
        );
        $this->saveTracking($request, $email, 'signed_preferences');

        return back()->with('status', 'preferences-updated');
    }

    public function updateAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lists' => ['array'],
            'lists.*' => [Rule::enum(MailingList::class)],
        ]);

        $validatedNewsletters = $request->validate([
            'newsletter_ids' => ['array'],
            'newsletter_ids.*' => ['integer', 'exists:newsletters,id'],
            'track_opens' => ['sometimes', 'boolean'],
            'track_clicks' => ['sometimes', 'boolean'],
        ]);

        $this->consent->syncAccountPreferences(
            $request->user(),
            $validated['lists'] ?? [],
            $request->ip(),
            $request->userAgent(),
        );
        $this->consent->syncNewsletterChoices(
            strtolower($request->user()->email),
            $validatedNewsletters['newsletter_ids'] ?? [],
            $request->user(),
            $request->ip(),
            $request->userAgent(),
        );
        $this->saveTracking($request, strtolower($request->user()->email), 'account_preferences');

        return back()->with('status', 'preferences-updated');
    }

    private function saveTracking(Request $request, string $email, string $source): void
    {
        if (! $request->has('track_opens') && ! $request->has('track_clicks')) {
            return;
        }

        $current = $this->consent->trackingPreferences($email);
        if ($current === null) {
            return;
        }

        $this->consent->setTrackingPreferences(
            $email,
            $request->has('track_opens') ? $request->boolean('track_opens') : $current['opens'],
            $request->has('track_clicks') ? $request->boolean('track_clicks') : $current['clicks'],
            $source,
            $request->ip(),
            $request->userAgent(),
        );
    }
}
