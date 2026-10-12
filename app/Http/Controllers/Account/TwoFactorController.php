<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
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
}
