<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Services\Channels\PublicChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsletterChannelSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function newsletter(string $slug): Newsletter
    {
        return Newsletter::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_migration_links_all_six_newsletters_to_channels_in_order(): void
    {
        $expected = [
            'apes-cic' => Channel::ApesCic,
            'apes-shelter-rescue' => Channel::ApesShelterRescue,
            'apes-pet-care-clinic' => Channel::ApesPetCareClinic,
            'apes-times' => Channel::ApesTimes,
            'myapes-portal' => Channel::MyapesPortal,
            'stream-4-apes' => Channel::Stream4Apes,
        ];

        $this->assertSame(
            array_map(fn (Channel $c) => $c->value, array_values($expected)),
            array_column(app(PublicChannels::class)->forListing(), 'value'),
        );

        foreach ($expected as $slug => $channel) {
            $newsletter = $this->newsletter($slug);
            $this->assertSame($channel, $newsletter->channel, $slug);
            $this->assertTrue($newsletter->show_on_home, $slug);
            $this->assertTrue($newsletter->show_in_nav, $slug);
        }
    }

    public function test_migration_is_idempotent_and_keeps_staff_settings(): void
    {
        $this->newsletter('apes-times')->update(['show_in_nav' => false, 'display_order' => 9]);

        $migration = require database_path('migrations/2026_10_26_130000_add_public_channel_settings_to_newsletters.php');
        $migration->up();

        $times = $this->newsletter('apes-times');
        $this->assertFalse($times->show_in_nav);
        $this->assertSame(9, $times->display_order);
        $this->assertSame(6, Newsletter::query()->whereNotNull('channel')->count());
    }

    public function test_channel_slugs_do_not_clash_with_other_top_level_routes(): void
    {
        $reserved = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route) => str_starts_with((string) $route->getName(), 'channels.'))
            ->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0])
            ->filter(fn (string $segment) => $segment !== '' && ! str_starts_with($segment, '{'))
            ->unique();

        foreach (Channel::cases() as $channel) {
            $this->assertNotContains($channel->slug(), $reserved, $channel->slug());
        }
    }

    public function test_staff_can_change_visibility_order_and_wording(): void
    {
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');

        $this->actingAs($staff)->patch('/staff/newsletters/'.$times->id, [
            'name' => 'APES Times',
            'slug' => 'apes-times',
            'channel' => Channel::ApesTimes->value,
            'show_on_home' => false,
            'show_in_nav' => true,
            'display_order' => 0,
            'public_label' => 'The Times',
            'public_description' => 'Our monthly magazine.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $channels = app(PublicChannels::class);
        $this->assertNotContains('apes-times', array_column($channels->forHome(), 'slug'));
        $nav = $channels->forNav();
        $this->assertSame('apes-times', $nav[0]['slug']);
        $this->assertSame('The Times', $nav[0]['label']);
        $this->assertSame('Our monthly magazine.', $nav[0]['description']);
        $this->assertSame('apes-times', $nav[0]['signup_slug']);
    }

    public function test_partial_update_keeps_channel_settings(): void
    {
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');

        $this->actingAs($staff)->patch('/staff/newsletters/'.$times->id, [
            'name' => 'APES Times',
            'sender_name' => 'The Times desk',
        ])->assertSessionHasNoErrors();

        $times->refresh();
        $this->assertSame(Channel::ApesTimes, $times->channel);
        $this->assertTrue($times->show_on_home);
        $this->assertSame(4, $times->display_order);
    }

    public function test_archived_newsletters_never_appear_publicly(): void
    {
        $this->newsletter('stream-4-apes')->update(['archived_at' => now()]);

        $channels = app(PublicChannels::class);
        $this->assertFalse($channels->isPublic(Channel::Stream4Apes));
        $this->assertNotContains('stream-4-apes', array_column($channels->forHome(), 'slug'));
        $this->assertNotContains('stream-4-apes', array_column($channels->forNav(), 'slug'));
    }

    public function test_slug_is_locked_while_linked_to_a_channel(): void
    {
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');

        $this->actingAs($staff)->patch('/staff/newsletters/'.$times->id, [
            'name' => 'APES Times',
            'slug' => 'times',
        ])->assertSessionHasErrors('slug');
        $this->assertSame('apes-times', $times->fresh()->slug);

        $this->actingAs($staff)->patch('/staff/newsletters/'.$times->id, [
            'name' => 'APES Times',
            'slug' => 'times',
            'channel' => '',
        ])->assertSessionHasNoErrors();

        $times->refresh();
        $this->assertSame('times', $times->slug);
        $this->assertNull($times->channel);
    }

    public function test_a_channel_can_only_be_linked_to_one_newsletter(): void
    {
        $staff = User::factory()->staff()->create();
        $other = Newsletter::query()->create(['name' => 'Foster updates', 'slug' => 'foster-updates']);

        $this->actingAs($staff)->patch('/staff/newsletters/'.$other->id, [
            'name' => 'Foster updates',
            'channel' => Channel::ApesTimes->value,
        ])->assertSessionHasErrors('channel');

        $this->actingAs($staff)->patch('/staff/newsletters/'.$other->id, [
            'name' => 'Foster updates',
            'channel' => 'not-a-channel',
        ])->assertSessionHasErrors('channel');

        $this->actingAs($staff)->get('/staff/newsletters/'.$other->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page
                ->has('channelOptions', 6)
                ->where('channelOptions', fn ($options) => collect($options)->firstWhere('value', 'apes_times')['taken_by'] === 'APES Times'));
    }

    public function test_post_editor_lists_active_channels_and_keeps_the_current_one(): void
    {
        $staff = User::factory()->staff()->create();
        $this->newsletter('myapes-portal')->update(['archived_at' => now()]);

        $this->actingAs($staff)->get('/staff/posts/new')
            ->assertInertia(fn (Assert $page) => $page
                ->where('channels', fn ($options) => collect($options)->pluck('value')->all() === [
                    'apes_cic', 'apes_shelter_rescue', 'apes_pet_care_clinic', 'apes_times', 'stream_4_apes',
                ]));

        $post = Post::factory()->create(['author_id' => $staff->id, 'channel' => Channel::MyapesPortal]);

        $this->actingAs($staff)->get('/staff/posts/'.$post->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('channels', fn ($options) => collect($options)->pluck('value')->contains('myapes_portal')));
    }
}
