<?php

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_renders_via_inertia(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('channels', 6)
            ->has('channels.0', fn (Assert $c) => $c
                ->has('slug')
                ->has('label')
                ->etc())
            ->has('recent')
            ->has('featured'));
    }

    public function test_homepage_card_payload_includes_hero_image_fields(): void
    {
        Post::factory()->published()->create([
            'hero_image' => 'https://example.test/hero.jpg',
            'hero_image_alt' => 'A capuchin monkey in habitat',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('featured.hero_image', 'https://example.test/hero.jpg')
                ->where('featured.hero_image_alt', 'A capuchin monkey in habitat'));
    }
}
