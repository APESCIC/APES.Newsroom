<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function newsletter(Channel $channel): Newsletter
    {
        return Newsletter::query()->where('channel', $channel)->firstOrFail();
    }

    public function test_every_channel_page_loads_with_its_posts_and_signup(): void
    {
        $author = User::factory()->staff()->create();

        foreach (Channel::cases() as $channel) {
            Post::factory()->published()->create([
                'author_id' => $author->id,
                'channel' => $channel,
                'title' => 'Story in '.$channel->label(),
            ]);

            $newsletter = $this->newsletter($channel);

            $this->get('/'.$channel->slug())
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Channels/Show')
                    ->where('channel.slug', $channel->slug())
                    ->where('channel.label', $newsletter->publicLabel())
                    ->where('channel.signup_slug', $newsletter->slug)
                    ->has('posts.data', 1)
                    ->where('posts.data.0.title', 'Story in '.$channel->label())
                    ->where('seo.url', url('/'.$channel->slug()))
                    ->where('seo.rss', url('/'.$channel->slug().'/rss.xml')));
        }
    }

    public function test_new_channel_without_posts_shows_an_empty_list(): void
    {
        $this->get('/stream-4-apes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('channel.label', 'Stream 4 APES')
                ->where('channel.description', 'Stream 4 APES fundraising updates.')
                ->has('posts.data', 0));
    }

    public function test_channel_page_html_carries_social_preview_tags_for_crawlers(): void
    {
        $this->newsletter(Channel::ApesTimes)->update(['public_description' => 'Our monthly magazine.']);

        $html = $this->get('/apes-times')->assertOk()->getContent();

        $this->assertStringContainsString('<meta inertia property="og:title" content="APES Times - '.config('app.name').'">', $html);
        $this->assertStringContainsString('<meta inertia property="og:description" content="Our monthly magazine.">', $html);
        $this->assertStringContainsString('<meta inertia property="og:image" content="'.url('/brand/apes-logo-compact.png').'">', $html);
        $this->assertStringContainsString('<link inertia rel="canonical" href="'.url('/apes-times').'">', $html);
        $this->assertStringContainsString('type="application/rss+xml"', $html);

        $this->assertStringNotContainsString('og:title', $this->get('/search')->getContent());
    }

    public function test_archived_channels_are_not_public(): void
    {
        $this->newsletter(Channel::MyapesPortal)->update(['archived_at' => now()]);

        $this->get('/myapes')->assertNotFound();
        $this->get('/myapes/rss.xml')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee(url('/myapes'), false);
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('channels', fn ($channels) => ! collect($channels)->pluck('slug')->contains('myapes'))
            ->where('publicChannels', fn ($channels) => ! collect($channels)->pluck('slug')->contains('myapes')));
    }

    public function test_the_myapes_channel_is_at_myapes_and_old_portal_urls_redirect(): void
    {
        $this->get('/myapes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Channels/Show')
            ->where('channel.label', 'MyAPES'));
        $this->get('/myapes/rss.xml')->assertOk();

        $this->get('/myapes-portal')->assertStatus(301)->assertRedirect('/myapes');
        $this->get('/myapes-portal/rss.xml')->assertStatus(301)->assertRedirect('/myapes/rss.xml');
        $this->get('/newsletters/myapes-portal/signup')->assertStatus(301)->assertRedirect('/newsletters/myapes/signup');
        $this->get('/newsletters/myapes/signup')->assertOk();
    }

    public function test_legacy_ghost_redirects_do_not_shadow_public_channels(): void
    {
        Redirect::query()->create(['from_path' => '/apes-times/', 'to_path' => '/articles/apes-times', 'status_code' => 301]);
        Redirect::query()->create(['from_path' => '/articles/old-story', 'to_path' => '/articles/new-story', 'status_code' => 301]);

        $this->get('/apes-times')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Channels/Show'));
        $this->get('/apes-times/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Channels/Show'));
        $this->get('/apes-times/rss.xml')->assertOk();
        $this->get('/articles/old-story')->assertRedirect('/articles/new-story');

        $this->newsletter(Channel::ApesTimes)->update(['archived_at' => now()]);

        $this->get('/apes-times')->assertRedirect('/articles/apes-times');
    }

    public function test_unknown_slugs_are_not_channel_routes(): void
    {
        $this->get('/not-a-channel')->assertNotFound();
    }

    public function test_channel_feeds_and_sitemap_include_the_new_channels(): void
    {
        $author = User::factory()->staff()->create();
        Post::factory()->published()->create([
            'author_id' => $author->id,
            'channel' => Channel::ApesTimes,
            'title' => 'Times feed story',
        ]);

        $this->get('/apes-times/rss.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml')
            ->assertSee('Times feed story')
            ->assertSee(config('app.name').' — APES Times', false);

        $sitemap = $this->get('/sitemap.xml')->assertOk();
        foreach (Channel::cases() as $channel) {
            $sitemap->assertSee('<loc>'.url('/'.$channel->slug()).'</loc>', false);
        }
    }

    public function test_home_page_shows_visible_channels_in_order_with_latest_posts(): void
    {
        $author = User::factory()->staff()->create();
        foreach (range(1, 4) as $day) {
            Post::factory()->published()->create([
                'author_id' => $author->id,
                'channel' => Channel::ApesTimes,
                'title' => 'Times story '.$day,
                'published_at' => now()->subDays(10 - $day),
            ]);
        }
        $this->newsletter(Channel::ApesTimes)->update(['display_order' => 0, 'public_label' => 'The Times']);
        $this->newsletter(Channel::ApesPetCareClinic)->update(['show_on_home' => false]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->has('channels', 5)
                ->where('channels.0.slug', 'apes-times')
                ->where('channels.0.label', 'The Times')
                ->where('channels.0.signup_slug', 'apes-times')
                ->has('channels.0.latest', 3)
                ->where('channels.0.latest.0.title', 'Times story 4')
                ->has('channels.1.latest', 0)
                ->where('channels', fn ($channels) => ! collect($channels)->pluck('slug')->contains('apes-pet-care-clinic'))
                ->where('recent', fn ($recent) => collect($recent)->every(fn ($card) => $card['channel'] === 'The Times'))
                ->has('publicChannels', 6)
                ->where('publicChannels.0.slug', 'apes-times'));
    }

    public function test_nav_menu_respects_show_in_nav(): void
    {
        $this->newsletter(Channel::Stream4Apes)->update(['show_in_nav' => false]);

        $this->get('/apes-cic')
            ->assertInertia(fn (Assert $page) => $page
                ->has('publicChannels', 5)
                ->where('publicChannels', fn ($channels) => ! collect($channels)->pluck('slug')->contains('stream-4-apes')));

        $this->get('/stream-4-apes')->assertOk();
    }
}
