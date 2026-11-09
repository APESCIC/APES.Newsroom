<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $vite = $this->viteDevOrigin();
        $viteSources = $vite === null ? '' : ' '.$vite;
        $viteConnect = $vite === null ? '' : ' '.$vite.' '.preg_replace('#^http#', 'ws', $vite);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), publickey-credentials-create=(self), publickey-credentials-get=(self)');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' data: blob: https:{$viteSources}; media-src 'self' https:; style-src 'self' 'unsafe-inline'{$viteSources}; script-src 'self' 'unsafe-inline'{$viteSources}; frame-src https://www.youtube.com https://player.vimeo.com https://www.instagram.com https://codepen.io https://platform.twitter.com; connect-src 'self'{$viteConnect}; font-src 'self' data:{$viteSources};"
        );

        if ($this->servedOverHttps($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    /**
     * The Vite dev server origin from public/hot, allowed only in the local
     * environment so hot reload works under the Laragon preview. Production
     * never has a hot file and never runs as local.
     */
    private function viteDevOrigin(): ?string
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
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
