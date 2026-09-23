<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewsletterAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_can_subscribe_to_multiple_newsletters_and_unsubscribe_one(): void
    {
        Notification::fake();

        $first = Newsletter::query()->create([
            'name' => 'Foster updates',
            'slug' => 'foster-updates',
            'description' => 'Foster notes',
        ]);
        $second = Newsletter::query()->create([
            'name' => 'Clinic diary',
            'slug' => 'clinic-diary',
            'description' => 'Clinic notes',
        ]);

        $consent = app(ConsentService::class);
        $a = $consent->subscribeToNewsletter('reader@example.com', $first, 'test');
        $b = $consent->subscribeToNewsletter('reader@example.com', $second, 'test');

        $this->assertNull($a->list);
        $this->assertNull($b->list);
        $this->assertNotSame($a->id, $b->id);

        $consent->confirm($a->confirm_token);
        $consent->confirm($b->fresh()->confirm_token);

        $this->assertSame(SubscriptionStatus::Confirmed, $a->fresh()->status);
        $this->assertSame(SubscriptionStatus::Confirmed, $b->fresh()->status);

        $consent->unsubscribeFromNewsletter('reader@example.com', $first);

        $this->assertSame(SubscriptionStatus::Unsubscribed, $a->fresh()->status);
        $this->assertSame(SubscriptionStatus::Confirmed, $b->fresh()->status);
    }

    public function test_account_preferences_can_request_two_newsletters(): void
    {
        Notification::fake();
        $this->withoutVite();

        $user = User::factory()->create(['email' => 'member@example.com']);
        $first = Newsletter::query()->create(['name' => 'One', 'slug' => 'one']);
        $second = Newsletter::query()->create(['name' => 'Two', 'slug' => 'two']);

        $this->actingAs($user)->post('/account/mailing', [
            'lists' => [],
            'newsletter_ids' => [$first->id, $second->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('mailing_list_subscriptions', [
            'newsletter_id' => $first->id,
            'status' => SubscriptionStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('mailing_list_subscriptions', [
            'newsletter_id' => $second->id,
            'status' => SubscriptionStatus::Pending->value,
        ]);
    }
}
