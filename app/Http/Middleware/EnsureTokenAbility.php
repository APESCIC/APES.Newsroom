<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the authenticated Admin API token to carry an ability, for
 * example `token.ability:posts:publish`.
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get(AuthenticateAdminApiToken::TOKEN_ATTRIBUTE);

        if (! $token instanceof ApiToken || ! $token->hasAbility($ability)) {
            return response()->json(['message' => "Token lacks the {$ability} ability."], 403);
        }

        return $next($request);
    }
}
