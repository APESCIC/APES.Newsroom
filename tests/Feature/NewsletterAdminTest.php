<?php

namespace Tests\Feature;

use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Models\User;
use App\Services\Mailing\ConsentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsletterAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_migration_seeds_channel_newsletters(): void
    {
        $this->assertSame(3, Newsletter::query()->whereNotNull('legacy_list')->count());
        $cic = Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->first();
        $this->assertNotNull($cic);
        $this->assertNull($cic->archived_at);
        $this->assertSame('apes-cic', $cic->slug);
    }

    public function test_migration_seeds_ghost_newsletters_with_senders(): void
    {
        $this->assertSame(6, Newsletter::query()->active()->count());
        $this->assertSame(0, Newsletter::query()->archived()->count());

        $expected = [
            'apes-cic' => ['APES Newsroom', 'app@apesnews.org.uk', 'default-newsletter'],
            'apes-times' => ['APES Times', 'times@apesnews.org.uk', 'apes-times'],
            'apes-shelter-rescue' => ['APES Shelter & Rescue', 'shelterandrescue@apesnews.org.uk', 'shelter-rescue'],
            'apes-pet-care-clinic' => ['APES Pet Care Clinic', 'noreply@apesnews.org.uk', 'apes-pet-care-clinic'],
            'myapes-portal' => ['MyAPES Portal', 'myapes@apesnews.org.uk', 'myapes-portal-newsroom'],
            'stream-4-apes' => ['Stream 4 APES', 'stream4apes@apesnews.org.uk', 'stream-4-apes'],
        ];

        foreach ($expected as $slug => [$name, $email, $ghostSlug]) {
            $newsletter = Newsletter::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame($name, $newsletter->sender_name, $slug);
            $this->assertSame($email, $newsletter->sender_email, $slug);
            $this->assertSame('info@apes.org.uk', $newsletter->reply_to, $slug);
            $this->assertSame($ghostSlug, $newsletter->ghost_slug, $slug);
            $this->assertTrue($newsletter->subscribe_on_signup, $slug);
            $this->assertSame(Newsletter::STATUS_ACTIVE, $newsletter->status());
        }

        foreach (['pet-shop-newsroom', 'mailing-list', 'animal-therapy-waiting-list'] as $slug) {
            $this->assertFalse(Newsletter::query()->where('slug', $slug)->exists(), $slug);
        }
    }

    public function test_delete_migration_removes_unused_archived_ghost_newsletters_only(): void
    {
        $unused = Newsletter::query()->create(['name' => 'Pet Shop Newsroom', 'slug' => 'pet-shop-newsroom', 'archived_at' => now()]);
        $referenced = Newsletter::query()->create(['name' => 'Mailing List', 'slug' => 'mailing-list', 'archived_at' => now()]);
        $active = Newsletter::query()->create(['name' => 'Animal Therapy Waiting List', 'slug' => 'animal-therapy-waiting-list']);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => MailingContact::factory()->create()->id,
            'newsletter_id' => $referenced->id,
            'list' => null,
            'status' => SubscriptionStatus::Confirmed,
            'confirm_token' => Str::random(64),
        ]);

        $migration = require database_path('migrations/2026_10_26_120000_delete_archived_ghost_newsletters.php');
        $migration->up();

        $this->assertNull(Newsletter::query()->find($unused->id));
        $this->assertNotNull(Newsletter::query()->find($referenced->id), 'kept while referenced');
        $this->assertNotNull(Newsletter::query()->find($active->id), 'only archived rows are deleted');
    }

    public function test_sender_migration_is_idempotent_and_keeps_staff_edits(): void
    {
        Newsletter::query()->where('slug', 'apes-times')->update(['sender_name' => 'Edited by staff']);

        $migration = require database_path('migrations/2026_10_26_100000_add_sender_and_signup_fields_to_newsletters.php');
        $migration->up();

        $this->assertSame(6, Newsletter::query()->count());
        $this->assertSame('Edited by staff', Newsletter::query()->where('slug', 'apes-times')->value('sender_name'));
    }

    public function test_staff_can_edit_sender_fields(): void
    {
        $staff = User::factory()->staff()->create();
        $newsletter = Newsletter::query()->where('slug', 'apes-times')->firstOrFail();

        $this->actingAs($staff)->patch('/staff/newsletters/'.$newsletter->id, [
            'name' => 'APES Times',
            'slug' => 'apes-times',
            'description' => '',
            'sender_name' => 'The Times desk',
            'sender_email' => 'Times@ApesNews.org.uk',
            'reply_to' => 'editor@apes.org.uk',
            'subscribe_on_signup' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $newsletter->refresh();
        $this->assertSame('The Times desk', $newsletter->sender_name);
        $this->assertSame('times@apesnews.org.uk', $newsletter->sender_email);
        $this->assertSame('editor@apes.org.uk', $newsletter->reply_to);
        $this->assertFalse($newsletter->subscribe_on_signup);
    }

    public function test_sender_email_must_use_an_approved_domain_and_reply_to_must_be_valid(): void
    {
        $staff = User::factory()->staff()->create();
        $newsletter = Newsletter::query()->where('slug', 'apes-times')->firstOrFail();

        $this->actingAs($staff)->patch('/staff/newsletters/'.$newsletter->id, [
            'name' => 'APES Times',
            'sender_email' => 'times@gmail.com',
            'reply_to' => 'not-an-email',
        ])->assertSessionHasErrors(['sender_email', 'reply_to']);

        $this->assertSame('times@apesnews.org.uk', $newsletter->fresh()->sender_email);
    }

    public function test_index_filters_by_status_and_counts_subscriptions_by_status(): void
    {
        $staff = User::factory()->staff()->create();
        $times = Newsletter::query()->where('slug', 'apes-times')->firstOrFail();
        Newsletter::query()->create(['name' => 'Old list', 'slug' => 'old-list', 'archived_at' => now()]);

        foreach ([SubscriptionStatus::Confirmed, SubscriptionStatus::Confirmed, SubscriptionStatus::Pending, SubscriptionStatus::Unsubscribed] as $status) {
            MailingListSubscription::query()->create([
                'mailing_contact_id' => MailingContact::factory()->create()->id,
                'newsletter_id' => $times->id,
                'list' => null,
                'status' => $status,
                'confirm_token' => Str::random(64),
            ]);
        }

        $this->actingAs($staff)->get('/staff/newsletters')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Newsletters/Index')
                ->has('newsletters', 7)
                ->where('newsletters', fn ($rows) => collect($rows)->firstWhere('slug', 'apes-times')['confirmed_count'] === 2
                    && collect($rows)->firstWhere('slug', 'apes-times')['pending_count'] === 1
                    && collect($rows)->firstWhere('slug', 'apes-times')['unsubscribed_count'] === 1));

        $this->actingAs($staff)->get('/staff/newsletters?status=archived')
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', 'archived')
                ->has('newsletters', 1)
                ->where('newsletters.0.status', 'archived'));

        $this->actingAs($staff)->get('/staff/newsletters?status=active')
            ->assertInertia(fn (Assert $page) => $page->has('newsletters', 6));
    }

    public function test_archived_newsletters_are_not_sendable_or_offered_as_segments(): void
    {
        $times = Newsletter::query()->where('slug', 'apes-times')->firstOrFail();
        $archived = Newsletter::query()->create(['name' => 'Old list', 'slug' => 'old-list', 'archived_at' => now()]);

        $contact = MailingContact::factory()->create(['email' => 'reader@example.com']);
        foreach ([$times, $archived] as $newsletter) {
            MailingListSubscription::query()->create([
                'mailing_contact_id' => $contact->id,
                'newsletter_id' => $newsletter->id,
                'list' => null,
                'status' => SubscriptionStatus::Confirmed,
                'confirm_token' => Str::random(64),
            ]);
        }

        $consent = app(ConsentService::class);
        $this->assertSame(['reader@example.com'], $consent->confirmedEmailsForNewsletter($times));
        $this->assertSame([], $consent->confirmedEmailsForNewsletter($archived));

        $segment = NewsletterSegment::query()->create([
            'newsletter_id' => $times->id,
            'also_newsletter_id' => $archived->id,
            'name' => 'Times and archived list',
        ]);
        $this->assertSame([], $consent->confirmedEmailsForSegment($segment));

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->get('/staff/posts/new')
            ->assertInertia(fn (Assert $page) => $page->has('segments', 0));
    }

    public function test_subscription_newsletter_unique_index_is_short_and_retryable(): void
    {
        $name = 'mailing_subs_contact_newsletter_uq';
        $this->assertLessThanOrEqual(64, strlen($name));

        $index = collect(Schema::getIndexes('mailing_list_subscriptions'))
            ->first(fn (array $index): bool => ($index['name'] ?? '') === $name);

        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);

        Schema::table('mailing_list_subscriptions', function (Blueprint $table) use ($name): void {
            $table->dropUnique($name);
        });

        $migration = require database_path('migrations/2026_09_23_101000_create_newsletters_table.php');
        $migration->up();

        $this->assertSame(3, Newsletter::query()->whereNotNull('legacy_list')->count());
        $this->assertSame(
            1,
            collect(Schema::getIndexes('mailing_list_subscriptions'))
                ->where('name', $name)
                ->count(),
        );
    }

    public function test_channel_signup_links_seeded_newsletter(): void
    {
        $contact = app(ConsentService::class)->signup(
            'reader@example.com',
            [MailingList::ApesCic->value],
            'test',
        );

        $subscription = $contact->subscriptions()->first();
        $newsletter = Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->first();
        $this->assertSame($newsletter?->id, $subscription?->newsletter_id);
        $this->assertSame(MailingList::ApesCic, $subscription?->list);
    }

    public function test_staff_can_create_and_archive_a_newsletter(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/newsletters', [
            'name' => 'Foster updates',
            'slug' => 'foster-updates',
            'description' => 'Notes for foster carers',
        ])->assertRedirect();

        $newsletter = Newsletter::query()->where('slug', 'foster-updates')->first();
        $this->assertNotNull($newsletter);
        $this->assertNull($newsletter->legacy_list);

        $this->actingAs($staff)->post('/staff/newsletters/'.$newsletter->id.'/archive')->assertRedirect();
        $this->assertNotNull($newsletter->fresh()->archived_at);

        $this->actingAs($staff)->get('/staff/newsletters')->assertOk();
    }

    public function test_public_cannot_manage_newsletters(): void
    {
        $this->get('/staff/newsletters')->assertRedirect();
    }
}
