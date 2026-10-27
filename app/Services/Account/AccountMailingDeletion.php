<?php

namespace App\Services\Account;

use App\Enums\ConsentAction;
use App\Models\ConsentEvent;
use App\Models\MailingContact;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\EmailAddress;
use Illuminate\Http\Request;

/**
 * When deleting an account, either keep the mailing contact as mailing-only
 * (default) or wipe the contact and its subscriptions (#509).
 */
class AccountMailingDeletion
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleBeforeUserDelete(User $user, bool $keepSubscriptions, ?Request $request = null): void
    {
        $email = EmailAddress::normalize((string) $user->email);
        $contact = MailingContact::query()
            ->forNormalizedEmail($email)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($contact === null) {
            return;
        }

        if ($keepSubscriptions) {
            // FK nullOnDelete leaves the contact mailing-only with subscriptions intact.
            $this->audit->record($user, 'account.mailing_kept_on_delete', $contact, [
                'email' => $email,
            ], $request);

            return;
        }

        ConsentEvent::create([
            'mailing_contact_id' => $contact->id,
            'email' => $email,
            'list' => null,
            'action' => ConsentAction::Unsubscribed,
            'source' => 'account_deletion',
            'wording_version' => 'v1',
            'evidence' => ['keep_newsletter_subscriptions' => false],
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);

        $this->audit->record($user, 'account.mailing_wiped_on_delete', $contact, [
            'email' => $email,
            'subscriptions_deleted' => $contact->subscriptions()->count(),
        ], $request);

        $contact->delete();
    }
}
