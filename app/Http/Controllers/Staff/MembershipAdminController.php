<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Membership\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin-only membership changes from the person page (#570). Complimentary
 * access sits beside the Stripe status and never changes it.
 */
class MembershipAdminController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly AuditLogger $audit,
    ) {}

    public function grantComp(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageComp', [Membership::class, $user]);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $membership = $this->memberships->ensureMembership($user);

        if ($membership->isComped()) {
            return $this->back($user, 'This member already has complimentary access.');
        }

        $membership->forceFill([
            'comped_at' => now(),
            'comped_by' => $request->user()->id,
            'comp_reason' => $validated['reason'],
        ])->save();

        $this->audit->record($request->user(), 'membership.comp_granted', $user, ['reason' => $validated['reason']], $request);

        return $this->back($user, 'Complimentary access granted.');
    }

    public function removeComp(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageComp', [Membership::class, $user]);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $membership = $user->membership;

        if (! $membership?->isComped()) {
            return $this->back($user, 'This member has no complimentary access.');
        }

        $membership->forceFill(['comped_at' => null, 'comped_by' => null, 'comp_reason' => null])->save();

        $this->audit->record($request->user(), 'membership.comp_removed', $user, ['reason' => $validated['reason']], $request);

        return $this->back($user, 'Complimentary access removed.');
    }

    public function updateNote(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('editNote', [Membership::class, $user]);
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $note = trim((string) ($validated['note'] ?? '')) ?: null;
        $membership = $this->memberships->ensureMembership($user);

        if ($note === $membership->staff_note) {
            return $this->back($user, 'No changes to save.');
        }

        $membership->forceFill([
            'staff_note' => $note,
            'staff_note_updated_at' => now(),
            'staff_note_updated_by' => $request->user()->id,
        ])->save();

        $this->audit->record($request->user(), 'membership.note_updated', $user, [
            'reason' => $validated['reason'],
            'cleared' => $note === null,
        ], $request);

        return $this->back($user, 'Note saved.');
    }

    private function back(User $user, string $status): RedirectResponse
    {
        return to_route('staff.people.users.show', $user)->with('status', $status);
    }
}
