<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class AnalyticsConsentController extends Controller
{
    public const COOKIE = 'newsroom_analytics_consent';

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'consent' => ['required', 'boolean'],
        ]);

        $value = $validated['consent'] ? '1' : '0';

        Cookie::queue(cookie(
            self::COOKIE,
            $value,
            minutes: 60 * 24 * 365,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: false,
            sameSite: 'Lax',
        ));

        return back();
    }
}
