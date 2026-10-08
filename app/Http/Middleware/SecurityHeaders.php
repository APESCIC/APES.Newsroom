<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' data: https:; media-src 'self' https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-src https://www.youtube.com https://player.vimeo.com https://www.instagram.com https://codepen.io https://platform.twitter.com; connect-src 'self'; font-src 'self' data:;"
        );

        if ($this->servedOverHttps($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    /**
     * Behind Cloudron's TLS-terminating proxy the request only looks secure
     * when CLOUDRON_PROXY_IP is trusted, so an https app URL also counts.
     * Browsers ignore HSTS received over plain HTTP, so this cannot pin a
     * local http:// setup.
     */
    private function servedOverHttps(Request $request): bool
    {
        return $request->isSecure() || str_starts_with((string) config('app.url'), 'https://');
    }
}
