<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodySearchAndChannelRssTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_search_matches_body_text_and_excludes_drafts(): void
    {
        Post::factory()->published()->create([
            'title' => 'Unrelated title',
            'excerpt' => 'Unrelated excerpt',
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'UniqueBodyPhrase for discovery']],
                ],
                'version' => '2.29.0',
            ],
        ]);

        Post::factory()->create([
            'status' => PostStatus::Draft,
            'title' => 'Draft with UniqueBodyPhrase',
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'UniqueBodyPhrase hidden']],
                ],
                'version' => '2.29.0',
            ],
        ]);

        $this->get('/search?q=UniqueBodyPhrase')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Search/Index')
                ->has('results', 1)
                ->where('results.0.title', 'Unrelated title'));
    }

    public function test_search_ranks_title_above_body(): void
    {
        Post::factory()->published()->create([
            'title' => 'Other story',
            'excerpt' => 'Quiet excerpt',
            'published_at' => now()->subDays(2),
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Contains RankToken in body']],
                ],
                'version' => '2.29.0',
            ],
        ]);

        Post::factory()->published()->create([
            'title' => 'RankToken in the title',
            'excerpt' => 'Different excerpt',
            'published_at' => now()->subDays(5),
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'No special phrase']],
                ],
                'version' => '2.29.0',
            ],
        ]);

        $this->get('/search?q=RankToken')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Search/Index')
                ->has('results', 2)
                ->where('results.0.title', 'RankToken in the title')
                ->where('results.1.title', 'Other story'));
    }

    public function test_channel_rss_filters_to_channel_and_site_rss_remains(): void
    {
        $cic = Post::factory()->published()->create([
            'slug' => 'cic-only-story',
            'channel' => Channel::ApesCic,
        ]);
        $shelter = Post::factory()->published()->create([
            'slug' => 'shelter-only-story',
            'channel' => Channel::ApesShelterRescue,
        ]);

        $cicRss = $this->get('/apes-cic/rss.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/articles/'.$cic->slug, $cicRss);
        $this->assertStringNotContainsString('/articles/'.$shelter->slug, $cicRss);
        $this->assertStringContainsString('APES CIC', $cicRss);

        $siteRss = $this->get('/rss.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/articles/'.$cic->slug, $siteRss);
        $this->assertStringContainsString('/articles/'.$shelter->slug, $siteRss);

        $this->get('/not-a-channel/rss.xml')->assertNotFound();
    }
}
