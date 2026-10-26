<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Middleware\EnsureAuthEpoch;
use App\Models\User;
use App\Services\Auth\PendingTwoFactorLogin;
use App\Services\Auth\RecentAuthentication;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Membership\FakeStripeBillingClient;
use App\Services\Membership\StripeApiBillingClient;
use App\Services\Membership\StripeBillingClient;
use App\Services\Settings\SettingsRepository;
use App\Support\Auth\PasswordPolicy;
use App\Support\Integrations\IntegrationDefinitions;
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
        $this->app->singleton(IntegrationSecrets::class);

        $this->app->bind(StripeBillingClient::class, function ($app) {
            $secrets = $app->make(IntegrationSecrets::class);
            $secret = $secrets->get(IntegrationDefinitions::STRIPE, 'secret');

            if ($app->environment('testing') || blank($secret)) {
                return $app->make(FakeStripeBillingClient::class);
            }

            return new StripeApiBillingClient(new StripeClient($secret), $secrets);
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

        $this->configurePasskeyRelyingParty();

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

        RateLimiter::for('media-upload', function (Request $request) {
            $perMinute = max(1, (int) config('newsroom.hero_uploads.rate_per_minute', 30));

            return Limit::perMinute($perMinute)->by('media-upload:'.($request->user()?->id ?: $request->ip()));
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

        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('two-factor-user:'.($request->session()->get(PendingTwoFactorLogin::SESSION_KEY.'.user_id') ?? $request->ip())),
            Limit::perMinute(20)->by('two-factor-ip:'.$request->ip()),
        ]);

        RateLimiter::for('two-factor-setup', fn (Request $request) => Limit::perMinute(5)->by('two-factor-setup:'.$request->user()?->id));

        RateLimiter::for('passkey-manage', fn (Request $request) => Limit::perMinute(10)->by('passkey-manage:'.$request->user()?->id));

        RateLimiter::for('passkey-login-options', fn (Request $request) => Limit::perMinute(30)->by('passkey-login-options:'.$request->ip()));

        RateLimiter::for('passkey-login', fn (Request $request) => Limit::perMinute(10)->by('passkey-login:'.$request->ip()));

        RateLimiter::for('integration-keys', fn (Request $request) => Limit::perMinute(10)->by('integration-keys:'.$request->user()?->id));

        RateLimiter::for('reauth', fn (Request $request) => [
            Limit::perMinute(5)->by('reauth-user:'.$request->user()?->id),
            Limit::perMinute(20)->by('reauth-ip:'.$request->ip()),
        ]);
    }

    /**
     * Runs at boot so the Cloudron origin (set during register) is final.
     */
    private function configurePasskeyRelyingParty(): void
    {
        $appUrl = parse_url((string) config('app.url'));
        $host = $appUrl['host'] ?? 'localhost';

        if (! config('passkeys.relying_party.id')) {
            config(['passkeys.relying_party.id' => $host]);
        }

        if (config('passkeys.allowed_origins') === []) {
            $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';
            config(['passkeys.allowed_origins' => [($appUrl['scheme'] ?? 'https').'://'.$host.$port]]);
        }
    }
}
