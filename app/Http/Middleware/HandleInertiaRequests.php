<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AnalyticsConsentController;
use App\Models\Post;
use App\Models\Release;
use App\Services\Channels\PublicChannels;
use App\Services\Mailing\SendingDomainAlerts;
use App\Services\Settings\SettingsRepository;
use App\Support\AnalyticsConfig;
use App\Support\Settings\SettingDefinitions;
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
        $shared = [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'role']),
                'can' => [
                    'accessStaff' => $request->user()?->can('access-staff-area') ?? false,
                    'accessAdmin' => $request->user()?->can('access-admin-area') ?? false,
                    'accessSuperAdmin' => $request->user()?->can('access-super-admin-area') ?? false,
                    'reviewPosts' => $request->user()?->can('review', Post::class) ?? false,
                ],
            ],
            'currentRelease' => fn () => $this->currentReleasePayload(),
            'publicChannels' => fn () => $this->publicChannelsPayload(),
            'devTools' => app()->environment('local'),
            'analytics' => fn () => [
                ...AnalyticsConfig::publicPayload(),
                'consent' => $request->cookie(AnalyticsConsentController::COOKIE) === '1',
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];

        if ($request->user()?->can('access-staff-area')) {
            $shared['editorDriver'] = fn () => app(SettingsRepository::class)->get(SettingDefinitions::EDITOR_DRIVER);
        }

        if ($request->user()?->can('access-admin-area')) {
            $shared['sendingDomainAlerts'] = fn () => $this->sendingDomainAlertsPayload();
        }

        return $shared;
    }

    /**
     * @return list<array{domain: string, message: string|null, newsletters: list<string>}>
     */
    private function sendingDomainAlertsPayload(): array
    {
        try {
            return app(SendingDomainAlerts::class)->failing();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Channels shown in the public navigation menu.
     *
     * @return list<array{value: string, slug: string, label: string, description: string|null, signup_slug: string}>
     */
    private function publicChannelsPayload(): array
    {
        try {
            return app(PublicChannels::class)->forNav();
        } catch (Throwable) {
            return [];
        }
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
