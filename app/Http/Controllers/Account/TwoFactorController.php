<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Notifications\Security\RecoveryCodesRegeneratedNotification;
use App\Notifications\Security\TwoFactorDisabledNotification;
use App\Notifications\Security\TwoFactorEnabledNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Authenticator-app setup for password and magic-link accounts. Staff use
 * Cloudron SSO, where MFA is enforced by Cloudron.
 */
class TwoFactorController extends Controller
{
    public const RECOVERY_CODES_FLASH = 'two_factor_recovery_codes';

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->requiresSso(), 403);

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('account.security')
                ->withErrors(['two_factor' => 'Two-factor authentication is already on.']);
        }

        $this->twoFactor->beginSetup($user);

        return redirect()->route('account.security');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->requiresSso(), 403);

        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $codes = $this->twoFactor->confirmSetup($user, $validated['code']);

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => 'That code did not work. Check the time on your device and enter the latest code from your authenticator app.',
            ]);
        }

        $this->audit->record($user, 'account.two_factor_enabled', $user, [], $request);
        $user->notify(new TwoFactorEnabledNotification);

        return redirect()->route('account.security')
            ->with('status', 'two-factor-enabled')
            ->with(self::RECOVERY_CODES_FLASH, $codes);
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 404);

        $codes = $this->twoFactor->replaceRecoveryCodes($user);

        $this->audit->record($user, 'account.recovery_codes_regenerated', $user, [], $request);
        $user->notify(new RecoveryCodesRegeneratedNotification);

        return redirect()->route('account.security')
            ->with('status', 'recovery-codes-regenerated')
            ->with(self::RECOVERY_CODES_FLASH, $codes);
    }

    /**
     * Turn two-factor off, or cancel a setup that was never confirmed.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $wasEnabled = $user->hasTwoFactorEnabled();

        $this->twoFactor->disable($user);

        if (! $wasEnabled) {
            return redirect()->route('account.security');
        }

        $this->audit->record($user, 'account.two_factor_disabled', $user, [], $request);
        $user->notify(new TwoFactorDisabledNotification);

        return redirect()->route('account.security')->with('status', 'two-factor-disabled');
    }
}
