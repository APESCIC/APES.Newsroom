<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentAuthentication;
use App\Support\Dev\DevLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DevLoginController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        abort_unless(DevLogin::enabled(), 404);

        return Inertia::render('Dev/Index', [
            'profiles' => DevLogin::picker(),
            'current' => DevLogin::profileFor($request->user()),
            'returnTo' => DevLogin::safeReturnPath($request->query('return_to')),
        ]);
    }

    public function loginForm(string $role): RedirectResponse
    {
        abort_unless(DevLogin::enabled(), 404);
        abort_unless(array_key_exists($role, DevLogin::roles()), 404);

        return redirect('/_dev')
            ->with('status', 'Use the developer sign-in buttons to switch role (POST only).');
    }

    public function login(Request $request, AuditLogger $audit, RecentAuthentication $recent): RedirectResponse
    {
        abort_unless(DevLogin::enabled(), 404);

        $validated = $request->validate([
            'profile' => ['required', 'string', Rule::in(array_keys(DevLogin::roles()))],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $previous = DevLogin::profileFor($request->user());
        $user = DevLogin::userFor($validated['profile']);

        Auth::login($user);
        $request->session()->regenerate();
        $recent->stamp($request);

        $audit->record($user, 'auth.signed_in', $user, array_filter([
            'method' => 'dev_login',
            'role' => $validated['profile'],
            'switched_from' => $previous,
        ], fn ($value) => $value !== null), $request);

        $returnTo = DevLogin::safeReturnPath($validated['return_to'] ?? null);
        if ($returnTo !== null || $request->session()->has('url.intended')) {
            $request->session()->flash(DevLogin::SWITCHED, true);
        }

        if ($returnTo !== null) {
            return redirect()->to($returnTo);
        }

        if ($request->session()->has('url.intended')) {
            return redirect()->intended(DevLogin::redirectFor($validated['profile']));
        }

        return redirect(DevLogin::redirectFor($validated['profile']));
    }

    /**
     * Legacy path used by the existing role switcher buttons.
     */
    public function loginRole(Request $request, string $role, AuditLogger $audit, RecentAuthentication $recent): RedirectResponse
    {
        $request->merge([
            'profile' => $role,
            'return_to' => $request->input('return_to'),
        ]);

        return $this->login($request, $audit, $recent);
    }

    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless(DevLogin::enabled(), 404);

        if ($request->user() !== null) {
            $audit->record($request->user(), 'auth.signed_out', $request->user(), [
                'method' => 'dev_login',
            ], $request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('login').'#developer-signin')
            ->with('status', 'You have signed out of the developer preview.');
    }
}
