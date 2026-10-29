<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\ConsentEvent;
use App\Models\ImportRun;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Suppression;
use App\Services\Import\GhostNewsletterReconciler;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GhostNewsletterReconcileTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var array<string, int> */
    private const FIXTURE_COUNTS = [
        'newsroom' => 3,
        'apes-times' => 1,
        'shelter-rescue' => 1,
        'pet-care-clinic' => 0,
        'myapes-portal' => 1,
        'stream-4-apes' => 0,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/ghost-newsletters-'.Str::random(8));
        File::copyDirectory(base_path('tests/fixtures/ghost/newsletters'), $this->dir);

        $mappings = collect(config('ghost_newsletters.newsletters'))
            ->map(fn (array $mapping) => [...$mapping, 'expected' => self::FIXTURE_COUNTS[$mapping['key']]])
            ->all();
        config(['ghost_newsletters.newsletters' => $mappings]);

        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function reconciler(): GhostNewsletterReconciler
    {
        return app(GhostNewsletterReconciler::class);
    }

    private function newsletter(string $slug): Newsletter
    {
        return Newsletter::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function confirmedEmails(string $slug): array
    {
        $newsletter = $this->newsletter($slug);

        return MailingListSubscription::query()
            ->where('newsletter_id', $newsletter->id)
            ->where('status', SubscriptionStatus::Confirmed)
            ->with('contact')
            ->get()
            ->pluck('contact.email')
            ->sort()
            ->values()
            ->all();
    }

    private function legacyImportRow(string $email, MailingList $list, SubscriptionStatus $status, string $source = GhostNewsletterReconciler::SOURCE): MailingListSubscription
    {
        $contact = MailingContact::query()->firstOrCreate(['email' => $email]);
        $row = MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => Newsletter::query()->where('legacy_list', $list->value)->value('id'),
            'list' => $list,
            'status' => $status,
            'confirm_token' => Str::random(64),
        ]);
        ConsentEvent::query()->create([
            'mailing_contact_id' => $contact->id,
            'email' => $email,
            'list' => $list,
            'action' => $status === SubscriptionStatus::Unsubscribed ? ConsentAction::Unsubscribed : ConsentAction::SignupRequested,
            'source' => $source,
            'wording_version' => 'ghost-import-v1',
            'evidence' => ['held' => true],
            'created_at' => now(),
        ]);

        return $row;
    }

    private function assertNothingSent(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_dry_run_reports_per_newsletter_counts_masks_emails_and_writes_nothing(): void
    {
        $report = $this->reconciler()->run($this->dir);

        $this->assertTrue($report['reconciled']);
        $this->assertSame([], $report['problems']);
        $this->assertSame(3, $report['newsletters']['newsroom']['in_export']);
        $this->assertSame(2, $report['newsletters']['newsroom']['to_confirm']);
        $this->assertSame(1, $report['newsletters']['newsroom']['blocked']['ghost_opt_out']);
        $this->assertSame(2, $report['members']['opted_out']);

        $json = json_encode(ImportRun::query()->firstOrFail()->report);
        $this->assertStringNotContainsString('carol@example.com', $json);
        $this->assertStringContainsString('c***@example.com', $json);

        $this->assertDatabaseCount('mailing_contacts', 0);
        $this->assertDatabaseCount('mailing_list_subscriptions', 0);
        $this->assertDatabaseCount('suppressions', 0);
        $this->assertDatabaseCount('consent_events', 0);
        $this->assertSame(1, ImportRun::query()->where('type', GhostNewsletterReconciler::RUN_TYPE)->where('dry_run', true)->count());
        $this->assertNothingSent();
    }

    public function test_apply_maps_each_ghost_newsletter_to_its_own_newsroom_newsletter(): void
    {
        $dryRun = $this->reconciler()->run($this->dir);
        $this->reconciler()->run($this->dir, apply: true, fromRun: ImportRun::query()->findOrFail($dryRun['import_run_id']));

        $this->assertSame(['alice@example.com', 'bob@example.com'], $this->confirmedEmails('apes-cic'));
        $this->assertSame(['alice@example.com'], $this->confirmedEmails('apes-times'));
        $this->assertSame(['bob@example.com'], $this->confirmedEmails('apes-shelter-rescue'));
        $this->assertSame([], $this->confirmedEmails('apes-pet-care-clinic'));
        $this->assertSame(['alice@example.com'], $this->confirmedEmails('myapes'));
        $this->assertSame([], $this->confirmedEmails('stream-4-apes'));
        $this->assertNull(MailingContact::query()->where('email', 'frank@example.com')->first(), 'subscribed member on no mapped newsletter gets nothing');

        $cic = MailingListSubscription::query()->where('newsletter_id', $this->newsletter('apes-cic')->id)->firstOrFail();
        $this->assertSame(MailingList::ApesCic, $cic->list);
        $times = MailingListSubscription::query()->where('newsletter_id', $this->newsletter('apes-times')->id)->firstOrFail();
        $this->assertNull($times->list);

        $this->assertTrue(Suppression::query()->where('email', 'carol@example.com')->exists());
        $this->assertTrue(Suppression::query()->where('email', 'dan@example.com')->exists());
        $this->assertNull(MailingContact::query()->where('email', 'erin@example.com')->first(), 'all-lists label is no longer consent evidence');

        $consent = app(ConsentService::class);
        $this->assertSame(['alice@example.com', 'bob@example.com'], collect($consent->confirmedEmailsForLists([MailingList::ApesCic]))->sort()->values()->all());

        $this->assertTrue(ConsentEvent::query()
            ->where('source', GhostNewsletterReconciler::SOURCE)
            ->where('wording_version', GhostNewsletterReconciler::WORDING_VERSION)
            ->where('action', ConsentAction::Confirmed)
            ->exists());
        $this->assertNothingSent();
    }

    public function test_repair_removes_held_import_rows_and_preserves_opt_outs_and_newsroom_choices(): void
    {
        foreach (MailingList::cases() as $list) {
            $this->legacyImportRow('erin@example.com', $list, SubscriptionStatus::Pending);
            $this->legacyImportRow('alice@example.com', $list, SubscriptionStatus::Pending);
        }
        $this->legacyImportRow('frank@example.com', MailingList::ApesPetCareClinic, SubscriptionStatus::Confirmed, 'account_preferences');
        $this->legacyImportRow('bob@example.com', MailingList::ApesCic, SubscriptionStatus::Unsubscribed, 'unsubscribe_link');
        $outsider = $this->legacyImportRow('newsroom-only@example.com', MailingList::ApesCic, SubscriptionStatus::Confirmed, 'double_opt_in');

        $dryRun = $this->reconciler()->run($this->dir);
        $this->assertSame(2, $dryRun['newsletters']['pet-care-clinic']['to_remove']);
        $this->assertSame(1, $dryRun['newsletters']['pet-care-clinic']['newsroom_choices_kept']);
        $this->assertSame(1, $dryRun['newsletters']['newsroom']['blocked']['newsroom_unsubscribed']);
        $this->assertSame(1, $dryRun['newsletters']['newsroom']['to_remove'], 'erin held on APES CIC');
        $this->assertSame(1, $dryRun['newsletters']['newsroom']['to_confirm'], 'alice upgraded from held');

        $this->reconciler()->run($this->dir, apply: true, fromRun: ImportRun::query()->findOrFail($dryRun['import_run_id']));

        $erin = MailingContact::query()->where('email', 'erin@example.com')->firstOrFail();
        $this->assertSame(0, $erin->subscriptions()->count(), 'held import rows with no Ghost newsletter are removed');

        $alice = MailingContact::query()->where('email', 'alice@example.com')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Confirmed, $alice->subscriptions()->where('list', MailingList::ApesCic->value)->firstOrFail()->status);
        $this->assertSame(0, $alice->subscriptions()->whereIn('list', [MailingList::ApesShelterRescue->value, MailingList::ApesPetCareClinic->value])->count());

        $bob = MailingContact::query()->where('email', 'bob@example.com')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Unsubscribed, $bob->subscriptions()->where('list', MailingList::ApesCic->value)->firstOrFail()->status, 'Newsroom opt-out wins');

        $frank = MailingContact::query()->where('email', 'frank@example.com')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Confirmed, $frank->subscriptions()->where('list', MailingList::ApesPetCareClinic->value)->firstOrFail()->status, 'Newsroom choice kept');

        $this->assertSame(SubscriptionStatus::Confirmed, $outsider->fresh()->status, 'non-Ghost contacts untouched');
        $this->assertNothingSent();
    }

    public function test_reruns_are_idempotent(): void
    {
        $first = $this->reconciler()->run($this->dir);
        $this->reconciler()->run($this->dir, apply: true, fromRun: ImportRun::query()->findOrFail($first['import_run_id']));
        $subscriptions = MailingListSubscription::query()->count();

        $second = $this->reconciler()->run($this->dir);
        foreach ($second['newsletters'] as $key => $counts) {
            $this->assertSame(0, $counts['to_confirm'], $key);
            $this->assertSame(0, $counts['to_remove'], $key);
        }
        $this->assertSame(0, $second['members']['suppressions_to_create']);

        $this->reconciler()->run($this->dir, apply: true, fromRun: ImportRun::query()->findOrFail($second['import_run_id']));
        $this->assertSame($subscriptions, MailingListSubscription::query()->count());
        $this->assertSame(1, Suppression::query()->where('email', 'carol@example.com')->count());
    }

    public function test_apply_requires_matching_completed_dry_run(): void
    {
        try {
            $this->reconciler()->run($this->dir, apply: true);
            $this->fail('Apply without a dry run should fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dry run', $e->getMessage());
        }

        $dryRun = $this->reconciler()->run($this->dir);
        file_put_contents($this->dir.'/stream-4-apes.csv', "email\nlate@example.com\n");

        $this->expectExceptionMessage('changed since that dry run');
        $this->reconciler()->run($this->dir, apply: true, fromRun: ImportRun::query()->findOrFail($dryRun['import_run_id']));
    }

    public function test_count_mismatch_blocks_apply_unless_accepted(): void
    {
        config(['ghost_newsletters.newsletters.0.expected' => 101]);

        $dryRun = $this->reconciler()->run($this->dir);
        $this->assertFalse($dryRun['reconciled']);
        $this->assertFalse($dryRun['newsletters']['newsroom']['matches_expected']);
        $run = ImportRun::query()->findOrFail($dryRun['import_run_id']);

        try {
            $this->reconciler()->run($this->dir, apply: true, fromRun: $run);
            $this->fail('Mismatch should block apply.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('accept-mismatch', $e->getMessage());
        }
        $this->assertDatabaseCount('mailing_list_subscriptions', 0);

        $this->reconciler()->run($this->dir, apply: true, fromRun: $run, acceptMismatch: true);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $this->confirmedEmails('apes-cic'));
    }

    public function test_missing_and_unmapped_files_are_problems(): void
    {
        unlink($this->dir.'/myapes-portal.csv');
        file_put_contents($this->dir.'/surprise.csv', "email\nx@example.com\n");

        $report = $this->reconciler()->run($this->dir);

        $this->assertFalse($report['reconciled']);
        $this->assertTrue($report['newsletters']['myapes-portal']['skipped']);
        $this->assertCount(2, $report['problems']);
        $this->assertStringContainsString('myapes-portal.csv', implode(' ', $report['problems']));
        $this->assertStringContainsString('surprise.csv', implode(' ', $report['problems']));

        $this->expectException(RuntimeException::class);
        $this->reconciler()->run($this->dir, apply: true, fromRun: ImportRun::query()->findOrFail($report['import_run_id']), acceptMismatch: true);
    }

    public function test_command_dry_runs_by_default_and_applies_from_run(): void
    {
        $this->artisan('ghost:reconcile-newsletters', ['dir' => $this->dir])
            ->expectsOutputToContain('Counts match Ghost')
            ->assertSuccessful();
        $this->assertDatabaseCount('mailing_list_subscriptions', 0);

        $run = ImportRun::query()->where('type', GhostNewsletterReconciler::RUN_TYPE)->latest('id')->firstOrFail();

        $this->artisan('ghost:reconcile-newsletters', ['dir' => $this->dir, '--apply' => true])->assertFailed();

        $this->artisan('ghost:reconcile-newsletters', ['dir' => $this->dir, '--apply' => true, '--from-run' => $run->id])
            ->doesntExpectOutputToContain('alice@example.com')
            ->assertSuccessful();
        $this->assertSame(['alice@example.com', 'bob@example.com'], $this->confirmedEmails('apes-cic'));

        config(['ghost_newsletters.newsletters.1.expected' => 9]);
        $this->artisan('ghost:reconcile-newsletters', ['dir' => $this->dir])->assertFailed();
        $this->assertNothingSent();
    }
}
