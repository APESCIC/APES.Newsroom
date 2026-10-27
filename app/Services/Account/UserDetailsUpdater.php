<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Account\ContactDetails;
use App\Support\EmailAddress;
use Illuminate\Http\Request;

/**
 * Applies a staff or admin edit of another account's name, email and contact
 * details. A new email address only starts the confirmation flow; it applies
 * once the account holder confirms it from the new inbox (#573).
 */
class UserDetailsUpdater
{
    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_SAVED = 'saved';

    public const RESULT_EMAIL_PENDING = 'email_pending';

    public function __construct(
        private readonly AccountEmailChangeService $emailChanges,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  output of UpdateUserRequest
     */
    public function update(User $actor, User $user, array $validated, Request $request): string
    {
        $contact = ContactDetails::only($validated);
        $contactChanged = ContactDetails::changedGroups($user, $contact);
        $nameChanged = $validated['name'] !== $user->name;
        $emailChanged = $validated['email'] !== EmailAddress::normalize((string) $user->email);

        if (! $nameChanged && ! $emailChanged && $contactChanged === []) {
            return self::RESULT_UNCHANGED;
        }

        $payload = ['reason' => $validated['reason']];

        if ($nameChanged) {
            $payload['name'] = ['from' => $user->name, 'to' => $validated['name']];
        }

        if ($contactChanged !== []) {
            $user->forceFill($contact)->save();
            $payload['contact_details_changed'] = $contactChanged;
        }

        if ($emailChanged) {
            $this->emailChanges->request($user, ['name' => $validated['name'], 'email' => $validated['email']]);
            $payload['email_change_requested'] = true;
        } elseif ($nameChanged) {
            $user->forceFill(['name' => $validated['name']])->save();
        }

        $this->audit->record($actor, 'user.updated', $user, $payload, $request);

        return $emailChanged ? self::RESULT_EMAIL_PENDING : self::RESULT_SAVED;
    }

    public static function message(string $result): string
    {
        return match ($result) {
            self::RESULT_UNCHANGED => 'No changes to save.',
            self::RESULT_EMAIL_PENDING => 'Changes saved. The new email address applies once the user confirms it from their inbox.',
            default => 'Changes saved.',
        };
    }
}
