<?php

namespace Tests\Feature;

use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\MailingContact;
use App\Models\Newsletter;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\ConfirmNewsletterNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsletterSignupDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function id(string $slug): int
    {
        return (int) Newsletter::query()->where('slug', $slug)->value('id');
    }

    public function test_register_page_offers_only_active_newsletters_flagged_for_signup(): void
    {
        Newsletter::query()->where('slug', 'stream-4-apes')->update(['subscribe_on_signup' => false]);

        $this->get('/register')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->has('newsletters', 5)
            ->where('newsletters', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Stream 4 APES')
                && collect($rows)->pluck('name')->doesntContain('Mailing List')));
    }

    public function test_ticked_newsletters_are_pending_until_email_is_verified_with_no_extra_emails(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'New Member',
            'email' => 'new@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'newsletter_ids' => [$this->id('apes-times'), $this->id('apes-cic'), $this->id('mailing-list')],
        ])->assertRedirect(route('verification.notice'));

        $contact = MailingContact::query()->where('email', 'new@example.com')->firstOrFail();
        $subscriptions = $contact->subscriptions()->get();

        $this->assertCount(2, $subscriptions);
        $this->assertTrue($subscriptions->every(fn ($s) => $s->status === SubscriptionStatus::Pending));
        $this->assertSame(MailingList::ApesCic, $subscriptions->firstWhere('newsletter_id', $this->id('apes-cic'))->list);
        $this->assertNull($subscriptions->firstWhere('newsletter_id', $this->id('apes-times'))->list);
        Notification::assertNotSentTo($contact, ConfirmMailingListNotification::class);
        Notification::assertNotSentTo($contact, ConfirmNewsletterNotification::class);

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->actingAs($user)->get(URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        ))->assertRedirect();

        $this->assertSame(2, $contact->subscriptions()->where('status', SubscriptionStatus::Confirmed)->count());
        $this->assertDatabaseHas('consent_events', [
            'mailing_contact_id' => $contact->id,
            'source' => 'registration_email_verified',
        ]);
    }

    public function test_unticked_or_unflagged_newsletters_are_never_subscribed(): void
    {
        Newsletter::query()->where('slug', 'apes-times')->update(['subscribe_on_signup' => false]);

        $this->post('/register', [
            'name' => 'Quiet Member',
            'email' => 'quiet@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'newsletter_ids' => [$this->id('apes-times')],
        ])->assertRedirect(route('verification.notice'));

        $contact = MailingContact::query()->where('email', 'quiet@example.com')->first();
        $this->assertSame(0, $contact?->subscriptions()->count() ?? 0);
    }
}
