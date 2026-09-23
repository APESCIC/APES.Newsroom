<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveRedirects;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Laravel's built-in readiness probe (boots the framework only).
        // The richer /health endpoint below additionally verifies DB and
        // Redis connectivity, per issue #3's "safe application readiness"
        // requirement, without exposing configuration or secrets.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ResolveRedirects::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);

        // Cloudron terminates TLS and proxies requests from a single,
        // known internal address (CLOUDRON_PROXY_IP). Trust only that
        // address so X-Forwarded-* headers (scheme, host, client IP)
        // are honoured without trusting arbitrary upstream proxies.
        $middleware->trustProxies(at: array_filter([env('CLOUDRON_PROXY_IP')]));

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('health') || $request->is('up')) {
                return $response;
            }

            $status = $response->getStatusCode();
            if (! in_array($status, [404, 410, 429, 500, 503], true)) {
                return $response;
            }

            return Inertia::render('Errors/Show', [
                'status' => $status,
                'title' => match ($status) {
                    404 => 'Page not found',
                    410 => 'Gone',
                    429 => 'Too many requests',
                    503 => 'Service unavailable',
                    default => 'Something went wrong',
                },
                'message' => match ($status) {
                    404 => 'We could not find that page in the APES Newsroom.',
                    410 => 'This content has been permanently removed.',
                    429 => 'Please wait a moment and try again.',
                    503 => 'The newsroom is temporarily unavailable.',
                    default => 'An unexpected error occurred. Please try again later.',
                },
            ])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
