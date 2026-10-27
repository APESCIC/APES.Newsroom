<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\MailingContact;
use App\Models\User;
use App\Services\Account\UserDetailsUpdater;
use App\Services\Membership\MemberCrmService;
use App\Services\People\PersonPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff People section (#567): public accounts and subscriber-only contacts.
 */
class PersonController extends Controller
{
    public function __construct(private readonly PersonPresenter $presenter) {}

    public function index(Request $request, MemberCrmService $crm): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,free,paying,comped'],
        ]);

        $search = $validated['q'] ?? null;
        $status = $validated['status'] ?? 'all';

        return Inertia::render('Staff/People/Index', [
            'filters' => ['q' => $search ?? '', 'status' => $status],
            'people' => $crm->paginate($search, $status),
        ]);
    }

    public function showUser(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        return Inertia::render('Staff/People/Show', [
            'person' => $this->presenter->forUser($user, $request->user()),
        ]);
    }

    public function showContact(Request $request, MailingContact $contact): Response|RedirectResponse
    {
        Gate::authorize('view', $contact);

        if (! $contact->isMailingOnly() && $contact->user) {
            return to_route('staff.people.users.show', $contact->user);
        }

        return Inertia::render('Staff/People/Show', [
            'person' => $this->presenter->forContact($contact, $request->user()),
        ]);
    }

    public function updateUser(UpdateUserRequest $request, User $user, UserDetailsUpdater $updater): RedirectResponse
    {
        $result = $updater->update($request->user(), $user, $request->validated(), $request);

        return to_route('staff.people.users.show', $user)->with('status', UserDetailsUpdater::message($result));
    }
}
