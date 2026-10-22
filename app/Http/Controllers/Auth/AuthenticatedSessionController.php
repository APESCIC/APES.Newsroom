<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\PendingTwoFactorLogin;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        $discoveryUrl = config('services.cloudron_oidc.discovery_url');

        return Inertia::render('Auth/Login', [
            'staffLoginUrl' => $discoveryUrl ? route('cloudron.redirect') : null,
            'staffLoginLabel' => $discoveryUrl
                ? 'Log in with '.config('services.cloudron_oidc.provider_name')
                : null,
            'rememberDays' => max(1, intdiv((int) config('auth.guards.web.remember', 43200), 1440)),
            'magicLinksEnabled' => (bool) app(SettingsRepository::class)->get(SettingDefinitions::MAGIC_LINKS_ENABLED),
        ]);
    }

    public function store(LoginRequest $request, PendingTwoFactorLogin $login): RedirectResponse
    {
        $user = $request->authenticate();

        return $login->signIn($request, $user, $request->boolean('remember'), 'password', route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
