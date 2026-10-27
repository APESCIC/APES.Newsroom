<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Engagement\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Staff and admin edits to a member's public profile fields (#572), beside
 * the admin moderation actions.
 */
class ProfileFieldsController extends Controller
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly AuditLogger $audit,
    ) {}

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('updateFields', $this->profileOf($user));
        $profile = $this->profiles->forUser($user);

        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'remove_avatar' => ['boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $changed = $this->profiles->staffUpdate(
            $profile,
            $request->user(),
            [
                'display_name' => trim((string) ($validated['display_name'] ?? '')) ?: null,
                'bio' => trim((string) ($validated['bio'] ?? '')) ?: null,
            ],
            $request->file('avatar'),
            $request->boolean('remove_avatar'),
        );

        if ($changed === []) {
            return $this->back($request, $user, 'No changes to save.');
        }

        $this->audit->record($request->user(), 'profile.fields_updated', $profile, [
            'reason' => $validated['reason'],
            'fields_changed' => $changed,
        ], $request);

        return $this->back($request, $user, 'Public profile saved.');
    }

    public function reset(Request $request, User $user): RedirectResponse
    {
        $profile = $this->profileOf($user);
        Gate::authorize('resetFields', $profile);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $cleared = $profile->exists ? $this->profiles->resetFields($profile, $request->user()) : [];

        if ($cleared === []) {
            return $this->back($request, $user, 'The public profile is already empty.');
        }

        $this->audit->record($request->user(), 'profile.fields_reset', $profile, [
            'reason' => $validated['reason'],
            'fields_reset' => $cleared,
        ], $request);

        return $this->back($request, $user, 'Public profile fields reset.');
    }

    /**
     * The saved profile, or an unsaved one so authorization runs before any
     * row is created.
     */
    private function profileOf(User $user): Profile
    {
        return $user->profile ?? (new Profile)->forceFill(['user_id' => $user->id])->setRelation('user', $user);
    }

    private function back(Request $request, User $user, string $status): RedirectResponse
    {
        $redirect = $request->input('return_to') === 'moderation'
            ? to_route('admin.moderation.index')
            : to_route('staff.people.users.show', $user);

        return $redirect->with('status', $status);
    }
}
