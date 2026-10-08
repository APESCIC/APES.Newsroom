<?php

namespace Tests\Feature;

use App\Providers\CloudronEnvironmentServiceProvider;
use Tests\TestCase;

class TransportSecurityTest extends TestCase
{
    public function test_session_cookies_default_to_secure_in_production(): void
    {
        $this->withEnvironment(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => null], function () {
            $this->assertTrue($this->freshSessionConfig()['secure']);
        });
    }

    public function test_session_cookies_are_not_forced_secure_locally(): void
    {
        $this->withEnvironment(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => null], function () {
            $this->assertFalse($this->freshSessionConfig()['secure']);
        });
    }

    public function test_an_explicit_session_secure_cookie_setting_wins(): void
    {
        $this->withEnvironment(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'], function () {
            $this->assertFalse($this->freshSessionConfig()['secure']);
        });
    }

    public function test_cloudron_https_origin_forces_secure_session_cookies(): void
    {
        config(['session.secure' => false]);

        $this->withEnvironment(['CLOUDRON_APP_ORIGIN' => 'https://news.example.test'], function () {
            (new CloudronEnvironmentServiceProvider($this->app))->register();
        });

        $this->assertTrue(config('session.secure'));
        $this->assertSame('https://news.example.test', config('app.url'));
    }

    public function test_https_responses_send_hsts(): void
    {
        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_an_https_app_url_sends_hsts_behind_an_untrusted_proxy(): void
    {
        config(['app.url' => 'https://www.apesnews.org.uk']);

        $this->get('/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_plain_http_local_responses_do_not_send_hsts(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_api_responses_carry_the_baseline_security_headers(): void
    {
        $this->getJson('https://localhost/api/content/v1/posts')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    /**
     * @return array<string, mixed>
     */
    private function freshSessionConfig(): array
    {
        return require config_path('session.php');
    }

    /**
     * @param  array<string, string|null>  $values  null unsets the variable
     */
    private function withEnvironment(array $values, callable $callback): void
    {
        $original = [];

        foreach ($values as $key => $value) {
            $original[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $this->setEnvironmentValue($key, $value);
        }

        try {
            $callback();
        } finally {
            foreach ($original as $key => [$getenv, $env, $server]) {
                $value = $getenv === false ? null : $getenv;
                $this->setEnvironmentValue($key, $value);

                if ($env !== null) {
                    $_ENV[$key] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
