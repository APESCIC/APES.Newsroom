<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Models\Post;
use App\Models\User;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewsletterSegmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_segment_send_intersects_confirmed_audiences_and_empty_completes(): void
    {
        Notification::fake();

        $primary = Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->firstOrFail();
        $also = Newsletter::query()->create(['name' => 'Foster', 'slug' => 'foster']);
        $segment = NewsletterSegment::query()->create([
            'newsletter_id' => $primary->id,
            'name' => 'CIC and foster',
            'also_newsletter_id' => $also->id,
        ]);

        $consent = app(ConsentService::class);
        $consent->signup('both@example.com', [MailingList::ApesCic->value], 'test');
        $consent->signup('only-cic@example.com', [MailingList::ApesCic->value], 'test');
        $this->confirmEmail('both@example.com');
        $this->confirmEmail('only-cic@example.com');
        $extra = $consent->subscribeToNewsletter('both@example.com', $also, 'test');
        $consent->confirm($extra->confirm_token);

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
            'newsletter_segment_id' => $segment->id,
        ]);

        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $admin);
        $this->assertNotNull($campaign);
        $this->assertEqualsCanonicalizing(['both@example.com'], $campaign->recipients->pluck('email')->all());

        $empty = NewsletterSegment::query()->create([
            'newsletter_id' => $primary->id,
            'name' => 'Nobody',
            'also_newsletter_id' => Newsletter::query()->create(['name' => 'Empty', 'slug' => 'empty'])->id,
        ]);
        $quiet = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'newsletter_segment_id' => $empty->id,
        ]);
        $emptyCampaign = app(CampaignService::class)->createFromPublishedPost($quiet, $admin);
        $this->assertNotNull($emptyCampaign);
        $this->assertSame(0, $emptyCampaign->recipients()->count());
        $this->assertSame(CampaignStatus::Completed, $emptyCampaign->fresh()->status);
    }

    private function confirmEmail(string $email): void
    {
        $tokens = MailingListSubscription::query()
            ->whereHas('contact', fn ($q) => $q->where('email', $email))
            ->where('status', SubscriptionStatus::Pending)
            ->pluck('confirm_token');

        foreach ($tokens as $token) {
            if ($token) {
                app(ConsentService::class)->confirm($token);
            }
        }
    }
}
