<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\ProcessGhostMembersImportJob;
use App\Models\ImportRun;
use App\Models\MailingContact;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Import\GhostMembersCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GhostMembersImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/fixtures/ghost/members.csv');
    }

    public function test_staff_cannot_access_importer(): void
    {
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->get('/admin/imports/ghost-members')->assertForbidden();
    }

    public function test_admin_can_queue_dry_run_upload(): void
    {
        Storage::fake();
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $file = new UploadedFile($this->fixture(), 'members.csv', 'text/csv', null, true);

        $this->actingAs($admin)->post('/admin/imports/ghost-members', [
            'csv' => $file,
            'mode' => 'dry-run',
        ])->assertRedirect();

        Queue::assertPushed(ProcessGhostMembersImportJob::class);
        $this->assertDatabaseHas('import_runs', [
            'type' => 'ghost_members_csv',
            'dry_run' => true,
            'actor_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'import.ghost_members.dry_run',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_admin_upload_cannot_run_a_writing_import(): void
    {
        Storage::fake();
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $file = new UploadedFile($this->fixture(), 'members.csv', 'text/csv', null, true);

        $this->actingAs($admin)->post('/admin/imports/ghost-members', [
            'csv' => $file,
            'mode' => 'import',
        ])->assertSessionHasErrors('mode');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_import_creates_contacts_and_opt_outs_only_and_is_idempotent_without_mail(): void
    {
        Mail::fake();
        Notification::fake();
        $importer = app(GhostMembersCsvImporter::class);

        $report = $importer->import($this->fixture(), dryRun: false);
        $this->assertSame(4, $report['members']['seen']);
        $this->assertSame(4, $report['members']['contacts_created']);
        $this->assertSame(2, $report['suppressions']);

        $this->assertDatabaseCount('mailing_list_subscriptions', 0);
        $active = MailingContact::query()->where('email', 'active-all@example.com')->firstOrFail();
        $this->assertSame(0, $active->subscriptions()->where('status', SubscriptionStatus::Confirmed)->count(), 'labels are not newsletter consent');

        $this->assertDatabaseHas('suppressions', ['email' => 'unsub@example.com']);
        $this->assertDatabaseHas('suppressions', ['email' => 'deleted@example.com']);

        $before = MailingContact::query()->count();
        $again = $importer->import($this->fixture(), dryRun: false);
        $this->assertSame($before, MailingContact::query()->count());
        $this->assertSame(4, $again['members']['contacts_matched']);
        $this->assertSame(0, $again['suppressions']);
        $this->assertSame(1, Suppression::query()->where('email', 'unsub@example.com')->count());

        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_dry_run_does_not_write_contacts(): void
    {
        Mail::fake();
        app(GhostMembersCsvImporter::class)->import($this->fixture(), dryRun: true);
        $this->assertDatabaseCount('mailing_contacts', 0);
        Mail::assertNothingSent();
    }

    public function test_job_processes_queued_run(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $path = storage_path('app/test-members.csv');
        copy($this->fixture(), $path);

        $run = ImportRun::create([
            'type' => 'ghost_members_csv',
            'status' => 'queued',
            'dry_run' => false,
            'source_path' => $path,
            'source_checksum' => hash_file('sha256', $path),
            'actor_id' => $admin->id,
        ]);

        (new ProcessGhostMembersImportJob($run->id, false))->handle(app(GhostMembersCsvImporter::class));

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->report);
        @unlink($path);
    }
}
