<?php

namespace App\Http\Middleware;

use App\Services\Auth\RecentAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentAuthentication
{
    public function __construct(private readonly RecentAuthentication $recent) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->recent->isRecent($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(423, 'Please confirm your identity to continue.');
        }

        return $this->recent->challenge($request);
    }
}
