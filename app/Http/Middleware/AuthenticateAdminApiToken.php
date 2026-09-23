<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdminApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->extractBearer($request);

        if ($plain === null) {
            return response()->json(['message' => 'Missing Admin API bearer token.'], 401);
        }

        $token = ApiToken::findByPlainToken($plain);

        if (! $token || ! $token->user) {
            return response()->json(['message' => 'Invalid Admin API token.'], 401);
        }

        if (! $token->user->role->atLeast(Role::Staff)) {
            return response()->json(['message' => 'Token owner lacks staff access.'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $authorization = $request->header('Authorization');
        if (! is_string($authorization) || ! preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            return null;
        }

        $plain = trim($matches[1]);

        return $plain !== '' ? $plain : null;
    }
}
