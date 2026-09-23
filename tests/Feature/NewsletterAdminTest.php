<?php

namespace Tests\Feature;

use App\Enums\MailingList;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\Mailing\ConsentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

        $this->assertSame(3, Newsletter::query()->count());
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
