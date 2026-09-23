<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountRequest;
use App\Services\Account\AccountDeletionPolicy;
use App\Services\Account\AccountEmailChangeService;
use App\Services\Membership\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountDeletionPolicy $deletionPolicy,
        private readonly AccountEmailChangeService $accountUpdater,
        private readonly MembershipService $memberships,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $membership = $this->memberships->provisionFreeMember($user);
        $deletionBlockReason = $this->deletionPolicy->blockingReason($user);

        return Inertia::render('Account/Profile', [
            'user' => $user->only(['name', 'email', 'role', 'auth_provider']),
            'membership' => [
                'status' => $membership->status->value,
                'status_label' => $membership->status->label(),
                'is_paying' => $membership->isPaying(),
                'interval' => $membership->interval,
                'current_period_end' => $membership->current_period_end?->toIso8601String(),
            ],
            'status' => session('status'),
            'can_delete_account' => $deletionBlockReason === null,
            'deletion_block_reason' => $deletionBlockReason,
        ]);
    }

    public function update(UpdateAccountRequest $request): RedirectResponse
    {
        $this->accountUpdater->update(
            $request->user(),
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('status', 'profile-updated');
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
            'membership' => $user->membership ? [
                'status' => $user->membership->status->value,
                'interval' => $user->membership->interval,
                'current_period_end' => $user->membership->current_period_end?->toIso8601String(),
            ] : null,
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
        $user = $request->user();
        $deletionBlockReason = DB::transaction(function () use ($user) {
            $lockedUser = $user->newQuery()->lockForUpdate()->findOrFail($user->id);
            $reason = $this->deletionPolicy->blockingReason($lockedUser);

            if ($reason === null) {
                $lockedUser->delete();
            }

            return $reason;
        });

        if ($deletionBlockReason !== null) {
            return back()->withErrors(['delete_account' => $deletionBlockReason]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
