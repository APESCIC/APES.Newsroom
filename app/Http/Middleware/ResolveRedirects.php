<?php

namespace App\Http\Middleware;

use App\Enums\Channel;
use App\Models\Redirect;
use App\Services\Channels\PublicChannels;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ResolveRedirects
{
    public function __construct(private readonly PublicChannels $channels) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        if ($request->is('health', 'up', 'sitemap.xml', 'rss.xml')) {
            return $next($request);
        }

        if (! Schema::hasTable('redirects')) {
            return $next($request);
        }

        $path = '/'.ltrim($request->getPathInfo(), '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        if ($this->isPublicChannelPath($path)) {
            return $next($request);
        }

        $redirect = Redirect::query()->where('from_path', $path)->first()
            ?? Redirect::query()->where('from_path', $path.'/')->first();

        if (! $redirect) {
            return $next($request);
        }

        if ((int) $redirect->status_code === 410) {
            abort(410);
        }

        return redirect($redirect->to_path, (int) $redirect->status_code);
    }

    /**
     * Imported Ghost posts can share a slug with a channel (for example a
     * post titled "APES Times"), so their legacy redirects must not shadow
     * a public channel page or feed.
     */
    private function isPublicChannelPath(string $path): bool
    {
        if (preg_match('#^/('.Channel::routePattern().')(/rss\.xml)?$#', $path, $matches) !== 1) {
            return false;
        }

        return $this->channels->findBySlug($matches[1]) !== null;
    }
}
