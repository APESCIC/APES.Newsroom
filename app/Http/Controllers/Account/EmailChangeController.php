<?php

namespace App\Http\Controllers\Account;

use App\Exceptions\EmailChangeFailed;
use App\Http\Controllers\Controller;
use App\Models\EmailChangeRequest;
use App\Services\Account\AccountEmailChangeService;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessionRevoker;
use App\Support\EmailAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Links from email open a page with a button rather than acting on GET, so
 * mail scanners that prefetch links cannot confirm or cancel a change.
 */
class EmailChangeController extends Controller
{
    public function __construct(
        private readonly AccountEmailChangeService $emailChanges,
        private readonly UserSessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function showConfirm(Request $request, EmailChangeRequest $emailChange): Response
    {
        return $this->page($request, $emailChange, $emailChange->isPending() ? 'confirm' : 'unavailable');
    }

    public function confirm(Request $request, EmailChangeRequest $emailChange): Response|RedirectResponse
    {
        try {
            $user = $this->emailChanges->confirm(
                $emailChange,
                (string) $request->query('token', ''),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (EmailChangeFailed $e) {
            return $this->page($request, $emailChange, 'unavailable', $e->getMessage());
        }

        $this->audit->record($user, 'account.email_changed', $user, [], $request);

        if ($request->user()?->is($user)) {
            $this->sessions->revokeOthers($request->user(), $request);

            return redirect()->route('account.show')->with('status', 'email-changed');
        }

        $this->sessions->revoke($user);

        return $this->page($request, $emailChange, 'confirmed');
    }

    public function showCancel(Request $request, EmailChangeRequest $emailChange): Response
    {
        return $this->page($request, $emailChange, $emailChange->isPending() ? 'cancel' : 'unavailable');
    }

    public function cancel(Request $request, EmailChangeRequest $emailChange): Response
    {
        if (! $this->emailChanges->cancel($emailChange)) {
            return $this->page($request, $emailChange, 'unavailable');
        }

        $this->audit->record($emailChange->user, 'account.email_change_cancelled', $emailChange->user, ['via' => 'email'], $request);

        return $this->page($request, $emailChange, 'cancelled');
    }

    public function resend(Request $request): RedirectResponse
    {
        $change = $request->user()->emailChangeRequests()->pending()->latest('id')->first();

        if ($change !== null) {
            $this->emailChanges->resend($change);
        }

        return back()->with('status', 'email-change-resent');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $change = $user->emailChangeRequests()->pending()->latest('id')->first();

        if ($change !== null && $this->emailChanges->cancel($change)) {
            $this->audit->record($user, 'account.email_change_cancelled', $user, ['via' => 'account'], $request);
        }

        return back()->with('status', 'email-change-cancelled');
    }

    private function page(Request $request, EmailChangeRequest $emailChange, string $state, ?string $message = null): Response
    {
        return Inertia::render('Account/EmailChange', [
            'state' => $state,
            'new_email' => EmailAddress::mask($emailChange->new_email),
            'action_url' => in_array($state, ['confirm', 'cancel'], true) ? $request->fullUrl() : null,
            'message' => $message,
        ]);
    }
}
