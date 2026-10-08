<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MagicLinkRequest;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\Auth\SsoOnlyPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MagicLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/MagicLinkRequest');
    }

    /**
     * Always responds the same way whether or not the email exists, to
     * avoid leaking which addresses have accounts.
     */
    public function store(MagicLinkRequest $request, SsoOnlyPolicy $ssoOnly): Response
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user && ! $ssoOnly->refuses($user, 'magic_link_request', $request)) {
            $token = Str::random(64);

            $user->magicLinkTokens()->create([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(15),
            ]);

            $url = URL::temporarySignedRoute(
                'magic-link.consume',
                now()->addMinutes(15),
                ['token' => $token],
            );

            $user->notify(new MagicLinkNotification($url));
        }

        return Inertia::render('Auth/MagicLinkSent');
    }

    public function consume(Request $request, string $token, SsoOnlyPolicy $ssoOnly): RedirectResponse
    {
        $magicLinkToken = MagicLinkToken::where('token_hash', hash('sha256', $token))->first();

        if (! $magicLinkToken || $magicLinkToken->isExpired() || $magicLinkToken->isUsed()
            || $ssoOnly->refuses($magicLinkToken->user, 'magic_link_consume', $request)) {
            abort(403, 'This magic link is invalid or has expired.');
        }

        $magicLinkToken->forceFill(['used_at' => now()])->save();

        Auth::login($magicLinkToken->user);

        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
