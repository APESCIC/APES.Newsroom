<?php

namespace Tests\Feature\Seo;

use App\Http\Middleware\NormalizePublicRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalHygieneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function trailing_slash_on_public_path_redirects_without_slash(): void
    {
        // Laravel's HTTP test client trims trailing slashes before the request
        // reaches the kernel, so exercise the middleware directly.
        $middleware = new NormalizePublicRequest;
        $response = $middleware->handle(
            Request::create('/search/', 'GET'),
            fn () => response('next'),
        );

        $this->assertTrue($response->isRedirection());
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(url('/search'), $response->headers->get('Location'));
    }

    #[Test]
    public function tracking_query_params_are_stripped(): void
    {
        $this->get('/search?q=apes&utm_source=newsletter&fbclid=abc')
            ->assertRedirect('/search?q=apes');
    }

    #[Test]
    public function search_is_noindex(): void
    {
        $this->get('/search')
            ->assertOk()
            ->assertSee('name="robots" content="noindex,nofollow"', false)
            ->assertSee('hreflang="en-GB"', false);
    }
}
