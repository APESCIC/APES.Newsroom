<?php

namespace App\Listeners;

use App\Events\Auth\MagicLinkConsumed;
use App\Events\Auth\MagicLinkRequested;
use App\Events\Auth\OidcSignInAllowed;
use App\Events\Auth\OidcSignInDenied;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

/**
 * Writes authentication events to audit_logs (registered by Laravel event
 * discovery). Payloads never contain credentials, tokens, links or, for
 * unknown accounts, email addresses.
 */
class RecordAuthenticationEvents
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Request $request,
    ) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record($event->user, 'auth.login', $event->user, [
            'guard' => $event->guard,
            'remember' => $event->remember,
        ], $this->request);
    }

    public function handleFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->audit->record(null, 'auth.failed', $user, [
            'guard' => $event->guard,
            'reason' => $user ? 'invalid_credentials' : 'unknown_account',
        ], $this->request);
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');
        $user = is_string($email) ? User::query()->where('email', $email)->first() : null;

        $this->audit->record(null, 'auth.lockout', $user, [
            'reason' => $user ? 'too_many_attempts' : 'unknown_account',
        ], $event->request);
    }

    public function handleLogout(Logout $event): void
    {
        // Sign-out straight after self-service deletion: the account is gone.
        if (! $event->user instanceof User || ! User::query()->whereKey($event->user->getKey())->exists()) {
            return;
        }

        $this->audit->record($event->user, 'auth.logout', $event->user, [
            'guard' => $event->guard,
        ], $this->request);
    }

    public function handleRegistered(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record($event->user, 'auth.registered', $event->user, [], $this->request);
        }
    }

    public function handleVerified(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record($event->user, 'auth.email_verified', $event->user, [], $this->request);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record($event->user, 'auth.password_reset', $event->user, [], $this->request);
        }
    }

    public function handleMagicLinkRequested(MagicLinkRequested $event): void
    {
        $this->audit->record(null, 'auth.magic_link_requested', $event->user, [], $this->request);
    }

    public function handleMagicLinkConsumed(MagicLinkConsumed $event): void
    {
        $this->audit->record($event->user, 'auth.magic_link_used', $event->user, [], $this->request);
    }

    public function handleOidcSignInAllowed(OidcSignInAllowed $event): void
    {
        $this->audit->record($event->user, 'auth.oidc_allowed', $event->user, [
            'role' => $event->user->role->value,
            'amr' => $event->amr,
        ], $this->request);
    }

    public function handleOidcSignInDenied(OidcSignInDenied $event): void
    {
        $this->audit->record(null, 'auth.oidc_denied', $event->user, [
            'reason' => $event->reason,
        ], $this->request);
    }
}
