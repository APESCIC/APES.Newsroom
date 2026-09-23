<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnsplashIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_search_is_noop_without_key(): void
    {
        config(['newsroom.unsplash.access_key' => null]);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->getJson(route('staff.media.unsplash.search', ['q' => 'cats']))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('results', []);
    }

    public function test_search_and_select_with_key(): void
    {
        config(['newsroom.unsplash.access_key' => 'test-key']);
        Http::fake([
            'api.unsplash.com/search/photos*' => Http::response([
                'results' => [[
                    'id' => 'abc',
                    'alt_description' => 'A cat',
                    'urls' => [
                        'thumb' => 'https://images.unsplash.com/thumb.jpg',
                        'regular' => 'https://images.unsplash.com/full.jpg',
                    ],
                    'user' => [
                        'name' => 'Jane',
                        'username' => 'jane',
                    ],
                ]],
            ], 200),
            'api.unsplash.com/photos/abc/download*' => Http::response([], 200),
        ]);

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->getJson(route('staff.media.unsplash.search', ['q' => 'cats']))
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('results.0.id', 'abc')
            ->assertJsonPath('results.0.credit', 'Photo by Jane on Unsplash');

        $this->actingAs($staff)
            ->postJson(route('staff.media.unsplash.select'), [
                'id' => 'abc',
                'url' => 'https://images.unsplash.com/full.jpg',
                'credit' => 'Photo by Jane on Unsplash',
                'alt' => 'A cat',
            ])
            ->assertOk()
            ->assertJsonPath('file.url', 'https://images.unsplash.com/full.jpg')
            ->assertJsonPath('file.credit', 'Photo by Jane on Unsplash');
    }

    public function test_guests_cannot_search_unsplash(): void
    {
        $this->getJson(route('staff.media.unsplash.search', ['q' => 'cats']))->assertUnauthorized();
    }
}
