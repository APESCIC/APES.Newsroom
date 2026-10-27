<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Services\Mailing\StaffContactService;
use App\Support\EmailAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff edits to mailing contacts and their subscriptions (#569, #570).
 */
class MailingContactController extends Controller
{
    public function __construct(private readonly StaffContactService $contacts) {}

    public function update(Request $request, MailingContact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $request->merge([
            'name' => trim((string) $request->input('name')) ?: null,
            'email' => EmailAddress::normalize((string) $request->input('email')),
        ]);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('mailing_contacts', 'email_normalized')->ignore($contact->id),
                ...($request->input('email') !== $contact->email_normalized ? [Rule::unique('users', 'email')] : []),
            ],
            ...$this->reasonRule(),
        ], [
            'email.unique' => 'Another subscriber or an account already uses this email address.',
        ]);

        $changed = $this->contacts->updateDetails($contact, $validated['name'], $validated['email'], $validated['reason'], $request->user(), $request);

        return $this->back($contact, match (true) {
            $changed === [] => 'No changes to save.',
            in_array('email', $changed, true) => 'Changes saved. Active subscriptions need confirming from the new address, so we sent a confirmation email.',
            default => 'Changes saved.',
        });
    }

    public function unsubscribe(Request $request, MailingContact $contact, MailingListSubscription $subscription): RedirectResponse
    {
        Gate::authorize('manageSubscriptions', $contact);
        $validated = $request->validate($this->reasonRule());

        $this->contacts->unsubscribe($contact, $subscription, $validated['reason'], $request->user(), $request);

        return $this->back($contact, 'Unsubscribed.');
    }

    public function subscribe(Request $request, MailingContact $contact, Newsletter $newsletter): RedirectResponse
    {
        Gate::authorize('manageSubscriptions', $contact);
        $validated = $request->validate($this->reasonRule());

        try {
            $this->contacts->requestSubscription($contact, $newsletter, $validated['reason'], $request->user(), $request);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['subscription' => $exception->getMessage()]);
        }

        return $this->back($contact, 'Confirmation email sent. The subscription starts once they confirm it.');
    }

    public function suppress(Request $request, MailingContact $contact): RedirectResponse
    {
        Gate::authorize('suppress', $contact);
        $validated = $request->validate($this->reasonRule());

        $this->contacts->suppress($contact, $validated['reason'], $request->user(), $request);

        return $this->back($contact, 'Address suppressed. No newsletters will be sent to it.');
    }

    public function unsuppress(Request $request, MailingContact $contact): RedirectResponse
    {
        Gate::authorize('suppress', $contact);
        $validated = $request->validate($this->reasonRule());

        $this->contacts->unsuppress($contact, $validated['reason'], $request->user(), $request);

        return $this->back($contact, 'Suppression removed. Withdrawn subscriptions stay withdrawn.');
    }

    public function destroy(Request $request, MailingContact $contact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        $validated = $request->validate([
            ...$this->reasonRule(),
            'confirm_email' => ['required', 'string'],
            'suppress' => ['boolean'],
        ]);

        if (EmailAddress::normalize($validated['confirm_email']) !== $contact->email_normalized) {
            throw ValidationException::withMessages(['confirm_email' => 'Type the subscriber\'s email address exactly to confirm.']);
        }

        $this->contacts->delete($contact, $validated['reason'], $request->boolean('suppress', true), $request->user(), $request);

        return to_route('staff.subscribers.index')->with('status', 'Subscriber deleted.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function reasonRule(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    private function back(MailingContact $contact, string $status): RedirectResponse
    {
        $contact->refresh();

        return $contact->user_id !== null
            ? to_route('staff.people.users.show', $contact->user_id)->with('status', $status)
            : to_route('staff.people.contacts.show', $contact)->with('status', $status);
    }
}
