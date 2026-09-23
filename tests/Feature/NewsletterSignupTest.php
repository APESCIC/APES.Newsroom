<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Newsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewsletterSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_signup_confirms_via_double_opt_in_and_embed_flag(): void
    {
        Notification::fake();
        $newsletter = Newsletter::query()->where('slug', 'apes-cic')->firstOrFail();

        $this->get('/newsletters/apes-cic/signup?embed=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mailing/NewsletterSignup')
                ->where('embed', true)
                ->where('newsletter.slug', 'apes-cic'));

        $this->post('/newsletters/apes-cic/signup', [])
            ->assertSessionHasErrors('email');

        $this->post('/newsletters/apes-cic/signup', ['email' => 'reader@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status', 'check-email');

        $this->assertDatabaseHas('mailing_list_subscriptions', [
            'newsletter_id' => $newsletter->id,
            'status' => SubscriptionStatus::Pending->value,
        ]);
    }

    public function test_archived_newsletter_signup_is_not_found(): void
    {
        $newsletter = Newsletter::query()->create([
            'name' => 'Old',
            'slug' => 'old-list',
            'archived_at' => now(),
        ]);

        $this->get('/newsletters/'.$newsletter->slug.'/signup')->assertNotFound();
        $this->post('/newsletters/'.$newsletter->slug.'/signup', ['email' => 'a@example.com'])->assertNotFound();
    }
}
