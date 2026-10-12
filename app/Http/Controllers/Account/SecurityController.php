<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\UserSession;
use App\Services\Auth\SessionTracker;
use App\Support\IpAddress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function show(Request $request, SessionTracker $tracker): Response
    {
        $user = $request->user();
        $currentId = $tracker->current($request)?->getKey();

        return Inertia::render('Account/Security', [
            'sso_managed' => $user->requiresSso(),
            'has_password' => filled($user->password),
            'status' => session('status'),
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
}
