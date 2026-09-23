<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Services\Membership\FakeStripeBillingClient;
use App\Services\Membership\StripeApiBillingClient;
use App\Services\Membership\StripeBillingClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FakeStripeBillingClient::class);

        $this->app->bind(StripeBillingClient::class, function ($app) {
            if ($app->environment('testing') || blank(config('services.stripe.secret'))) {
                return $app->make(FakeStripeBillingClient::class);
            }

            return new StripeApiBillingClient(
                new StripeClient((string) config('services.stripe.secret')),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-staff-area', fn (User $user) => $user->role->atLeast(Role::Staff));
        Gate::define('access-admin-area', fn (User $user) => $user->role->atLeast(Role::Admin));
        Gate::define('access-super-admin-area', fn (User $user) => $user->role->atLeast(Role::SuperAdmin));

        RateLimiter::for('content-api', function (Request $request) {
            $perMinute = max(1, (int) config('newsroom.content_api.rate_per_minute', 120));

            return Limit::perMinute($perMinute)->by($request->ip() ?? 'content-api');
        });

        RateLimiter::for('admin-api', function (Request $request) {
            $perMinute = max(1, (int) config('newsroom.admin_api.rate_per_minute', 60));

            return Limit::perMinute($perMinute)->by($request->user()?->id ?: ($request->ip() ?? 'admin-api'));
        });
    }
}
