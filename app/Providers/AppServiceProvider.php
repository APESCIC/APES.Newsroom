<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Middleware\EnsureAuthEpoch;
use App\Models\User;
use App\Services\Auth\RecentAuthentication;
use App\Services\Membership\FakeStripeBillingClient;
use App\Services\Membership\StripeApiBillingClient;
use App\Services\Membership\StripeBillingClient;
use App\Services\Settings\SettingsRepository;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FakeStripeBillingClient::class);
        $this->app->singleton(SettingsRepository::class);

        $this->app->bind(StripeBillingClient::class, function ($app) {
            if ($app->environment('testing') || blank(config('services.stripe.secret'))) {
                return $app->make(FakeStripeBillingClient::class);
            }

            return new StripeApiBillingClient(
                new StripeClient((string) config('services.stripe.secret')),
            );
        });

        $this->app->extend(UncompromisedVerifier::class, fn ($verifier, $app) => new NotPwnedVerifier(
            $app->make(HttpFactory::class),
            max(1, (int) config('security.password.breached_check_timeout', 5)),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-staff-area', fn (User $user) => $user->role->atLeast(Role::Staff));
        Gate::define('access-admin-area', fn (User $user) => $user->role->atLeast(Role::Admin));
        Gate::define('access-super-admin-area', fn (User $user) => $user->role->atLeast(Role::SuperAdmin));

        Password::defaults(fn () => PasswordPolicy::rule());

        Event::listen(Login::class, function (Login $event) {
            if ($event->guard === 'web' && $event->user instanceof User) {
                session()->put(EnsureAuthEpoch::SESSION_KEY, (int) $event->user->auth_epoch);

                if (! Auth::guard('web')->viaRemember()) {
                    app(RecentAuthentication::class)->stamp(request());
                }
            }
        });

        RateLimiter::for('content-api', function (Request $request) {
            $perMinute = max(1, (int) config('newsroom.content_api.rate_per_minute', 120));

            return Limit::perMinute($perMinute)->by($request->ip() ?? 'content-api');
        });

        RateLimiter::for('admin-api', function (Request $request) {
            $perMinute = max(1, (int) config('newsroom.admin_api.rate_per_minute', 60));

            return Limit::perMinute($perMinute)->by($request->user()?->id ?: ($request->ip() ?? 'admin-api'));
        });

        $this->configureAuthRateLimits();
    }

    /**
     * Limits for unauthenticated auth and signup endpoints. Keep
     * docs/auth-rate-limits.md in sync when changing these.
     */
    private function configureAuthRateLimits(): void
    {
        RateLimiter::for('magic-link', fn (Request $request) => [
            Limit::perMinute(3)->by('email:'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(10)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('mailing-signup', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('oidc-callback', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('user:'.$request->user()?->id));

        RateLimiter::for('email-change', fn (Request $request) => [
            Limit::perMinute(3)->by('email-change-user:'.$request->user()?->id),
            Limit::perMinute(10)->by('email-change-ip:'.$request->ip()),
        ]);

        RateLimiter::for('email-change-link', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('two-factor-setup', fn (Request $request) => Limit::perMinute(5)->by('two-factor-setup:'.$request->user()?->id));

        RateLimiter::for('reauth', fn (Request $request) => [
            Limit::perMinute(5)->by('reauth-user:'.$request->user()?->id),
            Limit::perMinute(20)->by('reauth-ip:'.$request->ip()),
        ]);
    }
}
