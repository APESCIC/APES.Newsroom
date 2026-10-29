<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Mailing\CampaignTrackingController;
use App\Jobs\SendCampaignRecipientJob;
use App\Mail\CampaignPostSummaryMail;
use App\Models\Campaign;
use App\Models\ConsentEvent;
use App\Models\MailEvent;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\CampaignStats;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class MailTrackingConsentTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->verifyNewsletterSenders();
        $this->admin = User::factory()->admin()->create();
    }

    private function contact(string $email = 'reader@example.com'): MailingContact
    {
        $contact = MailingContact::query()->create(['email' => $email]);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'list' => MailingList::ApesCic,
            'newsletter_id' => Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->value('id'),
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return $contact;
    }

    private function sendCampaign(): Campaign
    {
        $post = Post::factory()->create([
            'author_id' => $this->admin->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);
        Queue::fake();
        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $this->admin);
        foreach ($campaign->recipients as $recipient) {
            (new SendCampaignRecipientJob($recipient->id))->handle(app(ConsentService::class), app(CampaignSender::class));
        }

        return $campaign->fresh();
    }

    public function test_open_tracking_is_opt_in_and_click_tracking_opt_out_in_preferences(): void
    {
        $this->contact();
        $url = URL::temporarySignedRoute('mailing.preferences.signed', now()->addDay(), ['email' => 'reader@example.com']);

        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('tracking', ['opens' => false, 'clicks' => true]));

        $this->post(URL::temporarySignedRoute('mailing.preferences.signed.update', now()->addDay(), ['email' => 'reader@example.com']), [
            'lists' => [MailingList::ApesCic->value],
            'track_opens' => true,
            'track_clicks' => false,
        ])->assertRedirect();

        $this->assertSame(['opens' => true, 'clicks' => false], app(ConsentService::class)->trackingPreferences('reader@example.com'));
        $actions = ConsentEvent::query()->where('email', 'reader@example.com')->pluck('action')->all();
        $this->assertContains(ConsentAction::OpenTrackingConsented, $actions);
        $this->assertContains(ConsentAction::ClickTrackingOptedOut, $actions);
    }

    public function test_no_open_pixel_without_consent_and_plain_links_after_click_opt_out(): void
    {
        Mail::fake();
        $this->contact()->forceFill(['click_tracking_opted_out_at' => now()])->save();

        $this->sendCampaign();

        Mail::assertSent(CampaignPostSummaryMail::class, fn (CampaignPostSummaryMail $mail) => $mail->openPixelUrl === null
            && ! str_contains((string) $mail->trackedReadMoreUrl, '/mailing/track/'));
    }

    public function test_consenting_readers_get_newsroom_tracking_when_not_on_mailgun(): void
    {
        Mail::fake();
        $this->contact()->forceFill(['open_tracking_consented_at' => now()])->save();

        $this->sendCampaign();

        Mail::assertSent(CampaignPostSummaryMail::class, fn (CampaignPostSummaryMail $mail) => str_contains((string) $mail->openPixelUrl, '/mailing/track/open/')
            && str_contains((string) $mail->trackedReadMoreUrl, '/mailing/track/click/')
            && ! $mail->providerTrackOpens);
    }

    public function test_mailgun_sends_set_per_message_tracking_instead_of_newsroom_links(): void
    {
        Mail::fake();
        config(['services.mailgun.secret' => 'key-1234']);
        $this->contact();

        $campaign = $this->sendCampaign();

        Mail::assertSent(CampaignPostSummaryMail::class, function (CampaignPostSummaryMail $mail) use ($campaign) {
            $headers = $mail->headers()->text;

            return $mail->openPixelUrl === null
                && ! str_contains((string) $mail->trackedReadMoreUrl, '/mailing/track/')
                && $headers['X-Mailgun-Track-Opens'] === 'no'
                && $headers['X-Mailgun-Track-Clicks'] === 'yes'
                && json_decode($headers['X-Mailgun-Variables'], true) === ['campaign_recipient_id' => $campaign->recipients->first()->id];
        });
    }

    public function test_newsroom_clicks_are_recorded_for_top_links_and_stats(): void
    {
        Mail::fake();
        $this->contact()->forceFill(['open_tracking_consented_at' => now()])->save();
        $this->contact('second@example.com');
        $campaign = $this->sendCampaign();
        $recipient = $campaign->recipients()->where('email', 'reader@example.com')->firstOrFail();

        $this->get(CampaignTrackingController::openUrl($recipient))->assertOk();
        $this->get(CampaignTrackingController::clickUrl($recipient, 'https://www.apesnews.org.uk/articles/one'))->assertRedirect('https://www.apesnews.org.uk/articles/one');
        $this->get(CampaignTrackingController::clickUrl($recipient, 'https://www.apesnews.org.uk/articles/one'))->assertRedirect();

        $stats = app(CampaignStats::class)->forCampaign($campaign);
        $this->assertSame(2, $stats['sent']);
        $this->assertSame(1, $stats['opened']);
        $this->assertSame(1, $stats['clicked']);
        $this->assertSame(50.0, $stats['open_rate']);
        $this->assertSame([['url' => 'https://www.apesnews.org.uk/articles/one', 'clicks' => 1]], $stats['top_links']);
        $this->assertSame(2, MailEvent::query()->where('source', MailEvent::SOURCE_NEWSROOM)->count());

        $this->actingAs($this->admin)->get("/staff/posts/{$campaign->post_id}/campaign")
            ->assertInertia(fn (Assert $page) => $page
                ->where('liveCampaign.status', 'completed')
                ->where('liveCampaign.stats.clicked', 1)
                ->where('liveCampaign.stats.top_links.0.url', 'https://www.apesnews.org.uk/articles/one'));

        $this->actingAs($this->admin)->get('/staff/metrics')
            ->assertInertia(fn (Assert $page) => $page->where('newsletterBreakdown.0.sent', 2)->where('newsletterBreakdown.0.clicked', 1));
    }

    public function test_staff_see_why_an_address_is_blocked(): void
    {
        $contact = $this->contact('bounced@example.com');
        Suppression::query()->create(['email' => 'bounced@example.com', 'reason' => Suppression::BOUNCED, 'source' => 'mailgun']);
        MailEvent::query()->create([
            'event_id' => 'mailgun:x', 'type' => MailEvent::FAILED, 'severity' => 'permanent',
            'recipient' => 'bounced@example.com', 'reason' => '550 Mailbox does not exist', 'occurred_at' => now(),
        ]);

        $this->actingAs($this->admin)->get('/staff/subscribers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscribers.data.0.delivery_status.label', 'Bounced')
                ->where('subscribers.data.0.delivery_status.last_event.type', 'bounce')
                ->where('subscribers.data.0.delivery_status.last_event.reason', '550 Mailbox does not exist'));

        $this->actingAs($this->admin)->get("/staff/people/contacts/{$contact->id}")
            ->assertInertia(fn (Assert $page) => $page->where('person.mailing.delivery_status.reason', Suppression::BOUNCED));
    }
}
