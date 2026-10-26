<?php

namespace App\Http\Middleware;

use App\Services\Auth\RecentAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentAuthentication
{
    public function __construct(private readonly RecentAuthentication $recent) {}

    /**
     * Use as `auth.recent` or `auth.recent:300` for a stricter window in seconds.
     */
    public function handle(Request $request, Closure $next, ?string $withinSeconds = null): Response
    {
        if ($this->recent->isRecent($request, $withinSeconds === null ? null : (int) $withinSeconds)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            $request->session()->put('url.intended', url()->previous(route('account.show')));

            abort(423, 'Please confirm your identity to continue.');
        }

        return $this->recent->challenge($request);
    }
}
