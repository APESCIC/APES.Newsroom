<?php

namespace App\Http\Controllers;

use App\Support\LocalRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Public readiness endpoint.
     *
     * Verifies that the application can actually serve traffic - database
     * and cache/queue backing store are reachable - without exposing any
     * configuration, connection strings, or secrets in the response.
     *
     * Intentionally returns only a status and per-check booleans. Never
     * include exception messages, hostnames, credentials, or stack traces
     * here: this endpoint is public and unauthenticated by design so
     * Cloudron and external uptime monitors can call it.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $healthy = ! in_array(false, $checks, strict: true);

        $body = [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ];

        // Only the Herd preview gate reads this; production never shows it.
        if (app()->environment('local')) {
            $body['local'] = [
                'php' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'git_revision' => (new LocalRevision(base_path()))->current(),
            ];
        }

        return response()->json($body, $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        try {
            // Redis backs both cache and queue in every non-local
            // environment; a failed ping here means neither works.
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                Redis::connection()->ping();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
