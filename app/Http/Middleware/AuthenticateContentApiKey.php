<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateContentApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('newsroom.content_api.key', '');

        if ($configured === '') {
            return response()->json([
                'message' => 'Content API is not configured.',
            ], 503);
        }

        $provided = $this->extractKey($request);

        if ($provided === null || ! hash_equals($configured, $provided)) {
            return response()->json([
                'message' => 'Invalid or missing Content API key.',
            ], 401);
        }

        return $next($request);
    }

    private function extractKey(Request $request): ?string
    {
        $header = $request->header('X-Newsroom-Content-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $authorization = $request->header('Authorization');
        if (is_string($authorization) && preg_match('/^Newsroom-Key\s+(.+)$/i', trim($authorization), $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
