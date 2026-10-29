<?php

namespace App\Services\People;

use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ConsentEvent;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Membership;
use App\Models\Newsletter;
use App\Models\Profile;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Admin\AdminUserPresenter;
use App\Services\Mailing\DeliveryStatus;

/**
 * Allow-listed payloads for the staff person page (#568): one view of an
 * account, its membership, mailing contact, subscriptions and public profile.
 * Sections the viewer may not see are null rather than empty.
 */
class PersonPresenter
{
    private const CONSENT_HISTORY = 10;

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, User $viewer): array
    {
        $user->loadMissing(['membership.plan', 'membership.compedBy:id,name', 'membership.staffNoteUpdatedBy:id,name', 'profile', 'mailingContact']);
        $canUpdate = $viewer->can('update', $user);
        $canEditNote = $viewer->can('editNote', [Membership::class, $user]);
        $pending = $user->emailChangeRequests()->pending()->latest('id')->first();
        $contact = $user->mailingContact;

        return [
            'kind' => 'user',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'sign_in_method' => AdminUserPresenter::signInMethod($user),
                'sso_managed' => $user->auth_provider === 'cloudron_oidc',
                'email_verified' => $user->hasVerifiedEmail(),
                'locked' => $user->isLocked(),
                'created_at' => $user->created_at?->toIso8601String(),
                'pending_email' => $pending ? [
                    'email' => $pending->new_email,
                    'expires_at' => $pending->expires_at?->toIso8601String(),
                ] : null,
            ],
            'contact_details' => $canUpdate ? $user->contactDetails() : null,
            'membership' => $this->membership($user->membership, $canEditNote),
            'mailing' => $contact ? $this->mailing($contact) : null,
            'profile' => $this->profile($user->profile),
            'can' => [
                'update' => $canUpdate,
                'view_admin_user' => $viewer->can('viewAny', User::class),
                'manage_comp' => $viewer->can('manageComp', [Membership::class, $user]),
                'edit_note' => $canEditNote,
                'manage_subscriptions' => $contact !== null && $viewer->can('manageSubscriptions', $contact),
                'suppress' => $contact !== null && $viewer->can('suppress', $contact),
                'update_profile' => $user->profile !== null && $viewer->can('updateFields', $user->profile),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forContact(MailingContact $contact, User $viewer): array
    {
        return [
            'kind' => 'contact',
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'created_at' => $contact->created_at?->toIso8601String(),
            ],
            'mailing' => $this->mailing($contact),
            'can' => [
                'update' => $viewer->can('update', $contact),
                'delete' => $viewer->can('delete', $contact),
                'manage_subscriptions' => $viewer->can('manageSubscriptions', $contact),
                'suppress' => $viewer->can('suppress', $contact),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membership(?Membership $membership, bool $withNote): array
    {
        $status = $membership?->status ?? MembershipStatus::Free;

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'access_label' => $membership?->accessLabel() ?? $status->label(),
            'is_paying' => $status->isPaying(),
            'has_paid_access' => $membership?->hasPaidAccess() ?? false,
            'plan_name' => $membership?->plan?->name,
            'interval' => $membership?->plan?->interval ?? $membership?->interval,
            'current_period_end' => $membership?->current_period_end?->toIso8601String(),
            'stripe_linked' => filled($membership?->stripe_subscription_id),
            'comp' => $membership?->isComped() ? [
                'comped_at' => $membership->comped_at?->toIso8601String(),
                'by' => $membership->compedBy?->name,
                'reason' => $membership->comp_reason,
            ] : null,
            'note' => $withNote ? [
                'text' => $membership?->staff_note,
                'updated_at' => $membership?->staff_note_updated_at?->toIso8601String(),
                'updated_by' => $membership?->staffNoteUpdatedBy?->name,
            ] : null,
        ];
    }

    /**
     * Every active newsletter with this contact's status, plus subscriptions
     * that only exist on legacy lists or archived newsletters.
     *
     * @return array<string, mixed>
     */
    private function mailing(MailingContact $contact): array
    {
        $contact->loadMissing('subscriptions.newsletter');
        $subscriptions = $contact->subscriptions;
        $newsletters = Newsletter::query()->active()->orderBy('name')->get();
        $matched = [];

        $rows = $newsletters->map(function (Newsletter $newsletter) use ($subscriptions, &$matched): array {
            $subscription = $subscriptions->first(fn (MailingListSubscription $sub) => $sub->newsletter_id === $newsletter->id
                || ($newsletter->legacy_list !== null && $sub->list === $newsletter->legacy_list));

            if ($subscription) {
                $matched[] = $subscription->id;
            }

            return $this->subscriptionRow($subscription, $newsletter->id, $newsletter->name);
        });

        $orphans = $subscriptions
            ->reject(fn (MailingListSubscription $sub) => in_array($sub->id, $matched, true))
            ->map(fn (MailingListSubscription $sub) => $this->subscriptionRow(
                $sub,
                null,
                $sub->newsletter?->name ?? $sub->list?->label() ?? 'Unknown list',
            ));

        return [
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'linked' => ! $contact->isMailingOnly(),
            'suppressed' => Suppression::query()->where('email', $contact->email_normalized)->exists(),
            'delivery_status' => app(DeliveryStatus::class)->forEmail((string) $contact->email_normalized),
            'subscriptions' => $rows->concat($orphans)->values()->all(),
            'consent_history' => ConsentEvent::query()
                ->where('mailing_contact_id', $contact->id)
                ->latest('created_at')
                ->latest('id')
                ->limit(self::CONSENT_HISTORY)
                ->get(['id', 'action', 'source', 'created_at'])
                ->map(fn (ConsentEvent $event) => [
                    'id' => $event->id,
                    'action' => $event->action->value,
                    'source' => $event->source,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionRow(?MailingListSubscription $subscription, ?int $newsletterId, string $label): array
    {
        $status = $subscription?->status;

        return [
            'subscription_id' => $subscription?->id,
            'newsletter_id' => $newsletterId,
            'label' => $label,
            'status' => $status?->value,
            'confirmed_at' => $subscription?->confirmed_at?->toIso8601String(),
            'unsubscribed_at' => $subscription?->unsubscribed_at?->toIso8601String(),
            'can_unsubscribe' => in_array($status, [SubscriptionStatus::Confirmed, SubscriptionStatus::Pending], true),
            'can_subscribe' => $newsletterId !== null && ($status === null || $status === SubscriptionStatus::Unsubscribed),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profile(?Profile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'id' => $profile->id,
            'display_name' => $profile->display_name,
            'bio' => $profile->bio,
            'avatar_url' => $profile->avatar_path ? url('/storage/'.$profile->avatar_path) : null,
            'moderation_status' => $profile->moderation_status->value,
            'public_opt_in' => $profile->public_opt_in,
        ];
    }
}
