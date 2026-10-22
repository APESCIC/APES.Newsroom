<?php

namespace App\Http\Middleware;

use App\Models\ContentApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts any active named Content API key, or the NEWSROOM_CONTENT_API_KEY
 * environment key kept as a fallback while integrations move to named keys.
 */
class AuthenticateContentApiKey
{
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('newsroom.content_api.key', '');
        $provided = $this->extractKey($request);

        if ($provided !== null && ($key = ContentApiKey::findActive($provided)) !== null) {
            $this->touch($key);

            return $next($request);
        }

        if ($configured === '' && ! ContentApiKey::query()->active()->exists()) {
            return response()->json([
                'message' => 'Content API is not configured.',
            ], 503);
        }

        if ($provided === null || $configured === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'message' => 'Invalid or missing Content API key.',
            ], 401);
        }

        return $next($request);
    }

    private function touch(ContentApiKey $key): void
    {
        if ($key->last_used_at === null || $key->last_used_at->diffInSeconds(now()) >= self::TOUCH_INTERVAL_SECONDS) {
            $key->forceFill(['last_used_at' => now()])->save();
        }
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
