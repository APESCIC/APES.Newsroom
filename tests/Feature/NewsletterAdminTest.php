<?php

namespace Tests\Feature;

use App\Enums\MailingList;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(3, Newsletter::query()->count());
        $cic = Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->first();
        $this->assertNotNull($cic);
        $this->assertNull($cic->archived_at);
        $this->assertSame('apes-cic', $cic->slug);
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
