<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\MembershipPlan;
use App\Services\Account\AccountDeletionPolicy;
use App\Services\Account\AccountEmailChangeService;
use App\Services\Account\AccountEraser;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentAuthentication;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Membership\MembershipService;
use App\Support\EmailAddress;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountDeletionPolicy $deletionPolicy,
        private readonly AccountEmailChangeService $emailChanges,
        private readonly AccountEraser $eraser,
        private readonly MembershipService $memberships,
        private readonly IntegrationSecrets $secrets,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $membership = $this->memberships->provisionFreeMember($user);
        $deletionBlockReason = $this->deletionPolicy->blockingReason($user);
        $pendingEmail = $user->emailChangeRequests()->pending()->latest('id')->first();

        return Inertia::render('Account/Profile', [
            'user' => $user->only(['name', 'email', 'role', 'auth_provider']),
            'contact_details' => $user->contactDetails(),
            'pending_email' => $pendingEmail ? [
                'email' => $pendingEmail->new_email,
                'expires_at' => $pendingEmail->expires_at->toIso8601String(),
            ] : null,
            'membership' => [
                'status' => $membership->status->value,
                'status_label' => $membership->status->label(),
                'is_paying' => $membership->isPaying(),
                'interval' => $membership->interval,
                'current_period_end' => $membership->current_period_end?->toIso8601String(),
                'has_stripe_customer' => filled($membership->stripe_customer_id),
            ],
            'plans' => MembershipPlan::query()
                ->where('is_active', true)
                ->orderBy('interval')
                ->get()
                ->map(fn (MembershipPlan $plan) => [
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'interval' => $plan->interval,
                    'amount_pence' => $plan->amount_pence,
                    'currency' => $plan->currency,
                ]),
            'stripe_enabled' => $this->secrets->configured(IntegrationDefinitions::STRIPE, 'secret') || app()->environment('testing', 'local'),
            'status' => session('status'),
            'can_delete_account' => $deletionBlockReason === null,
            'deletion_block_reason' => $deletionBlockReason,
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

        if ($names === []) {
            return null;
        }

        return ['newsletters' => $names];
    }

    public function dismissMatchedMailingNotice(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['matched_mailing_notice_pending' => false])->save();

        return back();
    }

    public function update(UpdateAccountRequest $request, RecentAuthentication $recentAuth, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $emailChanged = EmailAddress::normalize((string) $request->validated('email')) !== EmailAddress::normalize((string) $user->email);

        if ($emailChanged && ! $recentAuth->isRecent($request)) {
            return $recentAuth->challenge($request);
        }

        $change = $this->emailChanges->request($user, $request->validated());

        if ($change === null) {
            return back()->with('status', 'profile-updated');
        }

        $audit->record($user, 'account.email_change_requested', $user, [], $request);

        return back()->with('status', 'email-change-requested');
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'auth_provider' => $user->auth_provider,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'contact_details' => $user->contactDetails(),
            'membership' => $user->membership ? [
                'status' => $user->membership->status->value,
                'interval' => $user->membership->interval,
                'current_period_end' => $user->membership->current_period_end?->toIso8601String(),
            ] : null,
            'security' => [
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'passkeys' => $user->passkeys()->oldest()->get()->map(fn ($passkey) => [
                    'name' => $passkey->name,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ])->all(),
            ],
        ];

        $filename = 'apes-newsroom-account-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'keep_newsletter_subscriptions' => ['sometimes', 'boolean'],
        ]);
        $keepSubscriptions = $request->boolean('keep_newsletter_subscriptions', true);

        $deletionBlockReason = $this->eraser->deleteIfAllowed($request->user(), $keepSubscriptions, $request);

        if ($deletionBlockReason !== null) {
            return back()->withErrors(['delete_account' => $deletionBlockReason]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
