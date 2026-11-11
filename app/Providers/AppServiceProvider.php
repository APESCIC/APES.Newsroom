<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Middleware\EnsureAuthEpoch;
use App\Models\User;
use App\Policies\NewsletterTemplatePolicy;
use App\Services\Auth\PendingTwoFactorLogin;
use App\Services\Auth\RecentAuthentication;
use App\Services\EmailTemplates\TemplatedMail;
use App\Services\Geo\DbIpGeoLocator;
use App\Services\Geo\GeoLocator;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mail\MailgunApiTransport;
use App\Services\Mail\MailgunDomainService;
use App\Services\Mail\MailTransports;
use App\Services\Membership\FakeStripeBillingClient;
use App\Services\Membership\StripeApiBillingClient;
use App\Services\Membership\StripeBillingClient;
use App\Services\Settings\SettingsRepository;
use App\Support\Auth\PasswordPolicy;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
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
        $this->app->scoped(MailgunDomainService::class);
        $this->app->singleton(GeoLocator::class, fn () => new DbIpGeoLocator((string) config('security.geoip.database')));

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
        Gate::define('manage-newsletter-templates', [NewsletterTemplatePolicy::class, 'manage']);

        Password::defaults(fn () => PasswordPolicy::rule());

        $this->configureTemplatedAuthMails();
        $this->configurePasskeyRelyingParty();

        // Laragon's Apache serves the site, so `composer dev` runs only the
        // queue listener and Vite (docs/local-dev.md).
        DevCommands::except('server');

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

        Mail::extend('mailgun-api', fn () => $this->app->make(MailgunApiTransport::class));
        $this->app->resolving('mail.manager', fn () => $this->app->make(MailTransports::class)->applySystemMailer());
        Event::listen(JobProcessing::class, fn () => $this->app->make(MailTransports::class)->applySystemMailer($this->app->make('mail.manager')));

        RateLimiter::for('admin-user-actions', function (Request $request) {
            $target = $request->route('user');
            $targetId = $target instanceof User ? $target->getKey() : (string) $target;

            return [
                Limit::perMinute(10)->by('admin-user-actions:'.$request->user()?->id.':'.$targetId),
                Limit::perMinute(30)->by('admin-user-actions:'.$request->user()?->id),
            ];
        });

        RateLimiter::for('people-actions', function (Request $request) {
            $target = $request->route('user') ?? $request->route('contact');
            $targetId = $target instanceof Model ? class_basename($target).':'.$target->getKey() : (string) $target;

            return [
                Limit::perMinute(10)->by('people-actions:'.$request->user()?->id.':'.$targetId),
                Limit::perMinute(30)->by('people-actions:'.$request->user()?->id),
            ];
        });

        RateLimiter::for('mailgun-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('mailing.webhook_per_minute', 600)))->by('mailgun-webhook:'.$request->ip()));

        RateLimiter::for('contact-details', fn (Request $request) => Limit::perMinute(10)->by('contact-details:'.$request->user()?->id));

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
     * Framework VerifyEmail / ResetPassword go through TemplatedMail so admin
     * overrides apply; without an override the Laravel defaults are unchanged.
     */
    private function configureTemplatedAuthMails(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url) {
            return app(TemplatedMail::class)->build(
                key: 'verify_email',
                placeholders: ['url' => $url],
                actionLabel: Lang::get('Verify Email Address'),
                actionUrl: $url,
                fallback: fn (): MailMessage => (new MailMessage)
                    ->subject(Lang::get('Verify your email address'))
                    ->line(Lang::get('Please click the button below to verify your email address.'))
                    ->action(Lang::get('Verify Email Address'), $url)
                    ->line(Lang::get('If you did not create an account, no further action is required.')),
            );
        });

        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));
            $expire = (string) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return app(TemplatedMail::class)->build(
                key: 'password_reset',
                placeholders: [
                    'url' => $url,
                    'expire_minutes' => $expire,
                ],
                actionLabel: Lang::get('Reset Password'),
                actionUrl: $url,
                fallback: fn (): MailMessage => (new MailMessage)
                    ->subject(Lang::get('Reset your password'))
                    ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
                    ->action(Lang::get('Reset Password'), $url)
                    ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => $expire]))
                    ->line(Lang::get('If you did not request a password reset, no further action is required.')),
            );
        });
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
