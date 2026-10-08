<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_magic_link_requests_are_limited_per_email(): void
    {
        $this->repeatPost(3, '/login/magic-link', ['email' => 'reader@example.com']);

        $this->assertThrottledFormPost($this->from('/login/magic-link')->post('/login/magic-link', ['email' => 'reader@example.com']), '/login/magic-link');
        $this->post('/login/magic-link', ['email' => 'someone-else@example.com'])->assertOk();
    }

    public function test_magic_link_requests_are_limited_per_ip(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post('/login/magic-link', ['email' => "reader{$i}@example.com"])->assertOk();
        }

        $this->assertThrottledFormPost($this->from('/login/magic-link')->post('/login/magic-link', ['email' => 'reader11@example.com']), '/login/magic-link');
    }

    public function test_registration_is_limited_per_ip(): void
    {
        $this->repeatPost(5, '/register', ['email' => 'not-an-email']);

        $this->assertThrottledFormPost($this->from('/register')->post('/register', ['email' => 'not-an-email']), '/register');
    }

    public function test_forgot_password_is_limited_per_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/forgot-password', ['email' => "reader{$i}@example.com"]);
        }

        $this->assertThrottledFormPost($this->from('/forgot-password')->post('/forgot-password', ['email' => 'reader6@example.com']), '/forgot-password');
    }

    public function test_mailing_signup_is_limited_per_ip(): void
    {
        $this->repeatPost(10, '/mailing/signup', []);

        $this->assertThrottledFormPost($this->from('/mailing/signup')->post('/mailing/signup', []), '/mailing/signup');
    }

    public function test_newsletter_signup_shares_the_mailing_signup_limit(): void
    {
        $this->repeatPost(10, '/newsletters/apes-cic/signup', []);

        $this->assertThrottledFormPost($this->from('/mailing/signup')->post('/mailing/signup', []), '/mailing/signup');
    }

    public function test_oidc_callback_is_limited_per_ip(): void
    {
        foreach (range(1, 20) as $i) {
            $this->get('/auth/cloudron/callback?state=x&code=y')->assertRedirect(route('login'));
        }

        $this->get('/auth/cloudron/callback?state=x&code=y')->assertStatus(429);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function repeatPost(int $times, string $uri, array $payload): void
    {
        foreach (range(1, $times) as $ignored) {
            $this->assertNotSame(429, $this->post($uri, $payload)->getStatusCode());
        }
    }

    private function assertThrottledFormPost(TestResponse $response, string $backTo): void
    {
        $response->assertRedirect($backTo);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('email'));
    }
}
