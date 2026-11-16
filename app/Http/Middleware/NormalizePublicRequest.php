<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical hygiene for public GETs: drop trailing slashes and strip tracking query noise (#673).
 */
class NormalizePublicRequest
{
    private const SKIP_PREFIXES = [
        'health',
        'up',
        'sitemap',
        'robots.txt',
        'rss.xml',
        'livewire',
        'sanctum',
        '_debugbar',
        'horizon',
        'telescope',
        'storage',
        'build',
        'brand',
        'vendor',
    ];

    /** @var list<string> */
    private const STRIP_QUERY_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'fbclid',
        'gclid',
        'msclkid',
        'mc_cid',
        'mc_eid',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $pathInfo = $request->getPathInfo();
        $path = ltrim($pathInfo, '/');
        foreach (self::SKIP_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $next($request);
            }
        }

        // Keep staff/admin/account/API paths alone (auth and app state).
        if ($request->is('staff*', 'admin*', 'account*', 'api*', 'login', 'logout', 'register', 'password*', 'email*', 'two-factor*', 'sanctum*', 'magic*')) {
            return $next($request);
        }

        $query = $request->query();
        $stripped = false;
        foreach (self::STRIP_QUERY_KEYS as $key) {
            if (array_key_exists($key, $query)) {
                unset($query[$key]);
                $stripped = true;
            }
        }

        // Prefer pathInfo (keeps a trailing slash from Request::create) over REQUEST_URI,
        // which some test clients rewrite without the slash.
        $needsSlashRedirect = $pathInfo !== '/' && str_ends_with($pathInfo, '/');

        if ($needsSlashRedirect || $stripped) {
            $cleanPath = $needsSlashRedirect ? (rtrim($pathInfo, '/') ?: '/') : $pathInfo;
            $url = url($cleanPath);
            if ($query !== []) {
                $url .= '?'.http_build_query($query);
            }

            return redirect()->to($url, 301);
        }

        return $next($request);
    }
}
