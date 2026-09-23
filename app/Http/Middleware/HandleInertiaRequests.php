<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Http\Controllers\AnalyticsConsentController;
use App\Models\Release;
use App\Support\AnalyticsConfig;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Keep this minimal and free of secrets: everything returned here is
     * serialized into the initial HTML payload and is visible to the client.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'role']),
                'can' => [
                    'accessStaff' => $request->user()?->role->atLeast(Role::Staff) ?? false,
                    'accessAdmin' => $request->user()?->role->atLeast(Role::Admin) ?? false,
                ],
            ],
            'currentRelease' => fn () => $this->currentReleasePayload(),
            'devTools' => app()->environment('local'),
            'analytics' => fn () => [
                ...AnalyticsConfig::publicPayload(),
                'consent' => $request->cookie(AnalyticsConsentController::COOKIE) === '1',
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }

    /**
     * @return array{version: string, slug: string}|null
     */
    private function currentReleasePayload(): ?array
    {
        try {
            $release = Release::query()
                ->published()
                ->where('is_current', true)
                ->first(['version', 'slug']);
        } catch (Throwable) {
            return null;
        }

        if ($release === null) {
            return null;
        }

        return [
            'version' => $release->version,
            'slug' => $release->slug,
        ];
    }
}
