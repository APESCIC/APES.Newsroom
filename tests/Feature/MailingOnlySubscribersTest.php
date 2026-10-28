<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\ConsentEvent;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Auth\LdapGroupLookup;
use App\Services\Auth\StaffOidcIdentity;
use App\Services\Auth\StaffReconciler;
use App\Services\Mailing\LinkMailingContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailingOnlySubscribersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function newsletter(string $slug = 'apes-times'): Newsletter
    {
        return Newsletter::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_staff_can_view_mailing_only_subscribers_and_public_cannot(): void
    {
        $times = $this->newsletter();
        $contact = MailingContact::factory()->create([
            'email' => 'ghost@example.com',
            'name' => 'Ghost Reader',
        ]);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $times->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        ConsentEvent::create([
            'mailing_contact_id' => $contact->id,
            'email' => $contact->email,
            'list' => null,
            'action' => ConsentAction::Confirmed,
            'source' => 'ghost_import',
            'wording_version' => 'v1',
            'evidence' => [],
            'created_at' => now(),
        ]);

        $linked = MailingContact::factory()->create([
            'email' => 'account@example.com',
            'user_id' => User::factory()->create(['email' => 'account@example.com'])->id,
        ]);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $linked->id,
            'newsletter_id' => $times->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/staff/subscribers')
            ->assertForbidden();

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get('/staff/subscribers?q=ghost')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Subscribers/Index')
                ->has('subscribers.data', 1)
                ->where('subscribers.data.0.email', 'ghost@example.com')
                ->where('subscribers.data.0.name', 'Ghost Reader'));

        $this->actingAs($staff)
            ->get('/staff/subscribers?newsletter_id='.$times->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscribers.data', 1)
                ->where('subscribers.data.0.email', 'ghost@example.com'));
    }

    public function test_subscriber_export_is_audited(): void
    {
        $staff = User::factory()->staff()->create();
        MailingContact::factory()->create(['email' => 'export-me@example.com']);

        $this->actingAs($staff)
            ->get('/staff/subscribers/export')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $staff->id,
            'action' => 'subscribers.exported',
        ]);
    }

    public function test_newsletter_index_shows_account_versus_mailing_only_counts(): void
    {
        $times = $this->newsletter();
        $mailingOnly = MailingContact::factory()->create(['email' => 'only@example.com']);
        $account = MailingContact::factory()->create([
            'email' => 'with-account@example.com',
            'user_id' => User::factory()->create(['email' => 'with-account@example.com'])->id,
        ]);

        foreach ([$mailingOnly, $account] as $contact) {
            MailingListSubscription::query()->create([
                'mailing_contact_id' => $contact->id,
                'newsletter_id' => $times->id,
                'status' => SubscriptionStatus::Confirmed,
                'confirmed_at' => now(),
            ]);
        }

        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff/newsletters')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('newsletters.data', fn ($rows) => collect($rows)->firstWhere('slug', 'apes-times')['account_confirmed_count'] === 1
                    && collect($rows)->firstWhere('slug', 'apes-times')['mailing_only_confirmed_count'] === 1
                    && collect($rows)->firstWhere('slug', 'apes-times')['confirmed_count'] === 2));
    }

    public function test_registration_respects_opt_outs_and_links_after_verify(): void
    {
        $times = $this->newsletter();
        $contact = MailingContact::factory()->create(['email' => 'opted@example.com']);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $times->id,
            'status' => SubscriptionStatus::Unsubscribed,
            'unsubscribed_at' => now(),
        ]);
        Suppression::query()->create([
            'email' => 'opted@example.com',
            'reason' => 'unsubscribe_all',
        ]);

        $this->post('/register', [
            'name' => 'Opted Out',
            'email' => 'opted@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'newsletter_ids' => [$times->id],
        ])->assertRedirect(route('verification.notice'));

        $contact->refresh();
        $this->assertNull($contact->user_id);
        $this->assertSame(
            SubscriptionStatus::Unsubscribed,
            $contact->subscriptions()->where('newsletter_id', $times->id)->first()->status,
        );

        $user = User::query()->where('email', 'opted@example.com')->firstOrFail();
        $this->actingAs($user)->get(URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        ))->assertRedirect();

        $this->assertSame($user->id, $contact->fresh()->user_id);
        $this->assertSame(
            SubscriptionStatus::Unsubscribed,
            $contact->fresh()->subscriptions()->where('newsletter_id', $times->id)->first()->status,
        );
    }

    public function test_link_service_is_idempotent_and_sets_matched_notice(): void
    {
        $user = User::factory()->create(['email' => 'match@example.com']);
        $contact = MailingContact::factory()->create(['email' => 'match@example.com']);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $this->newsletter()->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $service = app(LinkMailingContactService::class);
        $first = $service->link($user);
        $second = $service->link($user);

        $this->assertSame('linked', $first->outcome);
        $this->assertTrue($first->linkedPreExistingWithSubscriptions());
        $this->assertSame('already_linked', $second->outcome);
        $this->assertSame($user->id, $contact->fresh()->user_id);
        $this->assertTrue($user->fresh()->matched_mailing_notice_pending);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('matched_mailing_notice.newsletters', fn ($names) => count($names) >= 1));

        $this->actingAs($user)
            ->post('/account/mailing/matched-notice/dismiss')
            ->assertRedirect();

        $this->assertFalse($user->fresh()->matched_mailing_notice_pending);
    }

    public function test_staff_reconciler_links_mailing_contact_on_oidc_login(): void
    {
        $contact = MailingContact::factory()->create(['email' => 'staffer@example.com']);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $this->newsletter()->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $ldap = \Mockery::mock(LdapGroupLookup::class);
        $ldap->shouldReceive('groupsForEmail')->andReturn(['newsroom-staff']);

        $result = $this->app->make(StaffReconciler::class, ['ldap' => $ldap])->reconcile(new StaffOidcIdentity(
            sub: 'oidc-staff-1',
            email: 'staffer@example.com',
            name: 'Staffer',
            emailVerified: true,
        ));

        $this->assertTrue($result->allowed);
        $this->assertSame(Role::Staff, $result->user->role);
        $this->assertSame($result->user->id, $contact->fresh()->user_id);
    }

    public function test_backfill_command_dry_run_and_apply(): void
    {
        $verified = User::factory()->create(['email' => 'backfill@example.com']);
        $unverified = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        MailingContact::factory()->create(['email' => 'backfill@example.com']);
        MailingContact::factory()->create(['email' => 'pending@example.com']);

        $this->artisan('newsroom:link-mailing-contacts', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull(MailingContact::query()->forNormalizedEmail('backfill@example.com')->value('user_id'));

        $this->artisan('newsroom:link-mailing-contacts')
            ->assertSuccessful();

        $this->assertSame(
            $verified->id,
            MailingContact::query()->forNormalizedEmail('backfill@example.com')->value('user_id'),
        );
        $this->assertNull(
            MailingContact::query()->forNormalizedEmail('pending@example.com')->value('user_id'),
        );
        $this->assertNotNull($unverified->id);
    }

    public function test_account_deletion_keep_versus_wipe_subscriptions(): void
    {
        $keepUser = User::factory()->create(['email' => 'keep@example.com']);
        $keepContact = MailingContact::factory()->create([
            'email' => 'keep@example.com',
            'user_id' => $keepUser->id,
        ]);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $keepContact->id,
            'newsletter_id' => $this->newsletter()->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($keepUser)
            ->withRecentAuthentication()
            ->delete('/account', ['keep_newsletter_subscriptions' => true])
            ->assertRedirect('/');

        $this->assertDatabaseHas('mailing_contacts', [
            'email' => 'keep@example.com',
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('mailing_list_subscriptions', [
            'mailing_contact_id' => $keepContact->id,
            'status' => 'confirmed',
        ]);

        $wipeUser = User::factory()->create(['email' => 'wipe@example.com']);
        $wipeContact = MailingContact::factory()->create([
            'email' => 'wipe@example.com',
            'user_id' => $wipeUser->id,
        ]);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $wipeContact->id,
            'newsletter_id' => $this->newsletter()->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($wipeUser)
            ->withRecentAuthentication()
            ->delete('/account', ['keep_newsletter_subscriptions' => false])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('mailing_contacts', ['email' => 'wipe@example.com']);
        $this->assertDatabaseMissing('mailing_list_subscriptions', ['mailing_contact_id' => $wipeContact->id]);
    }
}
