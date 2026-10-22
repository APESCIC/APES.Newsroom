<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\RecentAuthentication;
use App\Services\Auth\SessionTracker;
use App\Services\Auth\TwoFactor\TwoFactorService;
use App\Services\Auth\TwoFactorRequirement;
use App\Support\IpAddress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function show(
        Request $request,
        SessionTracker $tracker,
        TwoFactorService $twoFactor,
        RecentAuthentication $recent,
        TwoFactorRequirement $requirement,
    ): Response {
        $user = $request->user();
        $currentId = $tracker->current($request)?->getKey();

        return Inertia::render('Account/Security', [
            'sso_managed' => $user->requiresSso(),
            'two_factor_required' => $requirement->isUnmet($user),
            'has_password' => filled($user->password),
            'status' => session('status'),
            'two_factor' => $user->requiresSso() ? null : $this->twoFactorState($user, $twoFactor, $recent->isRecent($request)),
            'recovery_codes' => session(TwoFactorController::RECOVERY_CODES_FLASH),
            'passkeys' => $user->requiresSso() ? null : $user->passkeys()
                ->oldest()
                ->get()
                ->map(fn (Passkey $passkey) => [
                    'id' => $passkey->getKey(),
                    'name' => $passkey->name,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ])
                ->values(),
            'sessions' => $user->sessions()
                ->live()
                ->latest('last_seen_at')
                ->get()
                ->map(fn (UserSession $session) => [
                    'id' => $session->getKey(),
                    'browser' => $session->browser,
                    'platform' => $session->platform,
                    'device_type' => $session->device_type,
                    'ip' => IpAddress::mask($session->ip_address),
                    'last_active_at' => $session->last_seen_at?->toIso8601String(),
                    'remembered' => $session->remembered,
                    'current' => $session->getKey() === $currentId,
                ])
                ->sortByDesc('current')
                ->values(),
        ]);
    }

    /**
     * The setup secret is only sent while identity was recently confirmed,
     * so a lapsed session must restart setup rather than reveal it.
     *
     * @return array{enabled: bool, recovery_codes_remaining: int, setup: array{qr_image: string, secret: string}|null}
     */
    private function twoFactorState(User $user, TwoFactorService $twoFactor, bool $recentlyConfirmed): array
    {
        $pending = ! $user->hasTwoFactorEnabled() && $user->two_factor_secret !== null;

        return [
            'enabled' => $user->hasTwoFactorEnabled(),
            'recovery_codes_remaining' => $user->hasTwoFactorEnabled() ? $twoFactor->remainingRecoveryCodes($user) : 0,
            'setup' => $pending && $recentlyConfirmed ? [
                'qr_image' => 'data:image/svg+xml;base64,'.base64_encode($twoFactor->qrSvg($user, $user->two_factor_secret)),
                'secret' => $user->two_factor_secret,
            ] : null,
        ];
    }
}
