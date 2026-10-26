<?php

namespace App\Services\Import;

use App\Enums\ConsentAction;
use App\Models\ConsentEvent;
use App\Models\ImportRun;
use App\Models\MailingContact;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports Ghost members as contacts and records Ghost opt-outs as
 * suppressions. Newsletter subscriptions are not created here: they come
 * from per-newsletter exports via `ghost:reconcile-newsletters`.
 */
class GhostMembersCsvImporter
{
    use SuppressesOutboundMail;

    /**
     * @return array<string, mixed>
     */
    public function import(string $csvPath, bool $dryRun = true, ?User $actor = null, ?ImportRun $existingRun = null): array
    {
        return $this->withoutOutboundMail(function () use ($csvPath, $dryRun, $actor, $existingRun) {
            if (! is_file($csvPath)) {
                throw new RuntimeException("Ghost members CSV not found: {$csvPath}");
            }

            $checksum = hash_file('sha256', $csvPath);
            $run = $existingRun ?? ImportRun::create([
                'type' => 'ghost_members_csv',
                'status' => 'running',
                'dry_run' => $dryRun,
                'source_path' => $csvPath,
                'source_checksum' => $checksum,
                'actor_id' => $actor?->id,
                'started_at' => now(),
            ]);

            if ($existingRun) {
                $run->update([
                    'status' => 'running',
                    'dry_run' => $dryRun,
                    'started_at' => now(),
                ]);
            }

            $report = [
                'members' => ['seen' => 0, 'contacts_created' => 0, 'contacts_matched' => 0],
                'suppressions' => 0,
                'consent_events' => 0,
                'subscriptions' => 'Not changed here. Newsletter subscriptions come from per-newsletter exports via ghost:reconcile-newsletters.',
                'warnings' => [],
            ];

            try {
                foreach (GhostMembersCsv::rows($csvPath) as $member) {
                    $this->importMember($member, $dryRun, $report);
                }

                $run->update([
                    'status' => 'completed',
                    'report' => $report,
                    'finished_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $report['warnings'][] = $e->getMessage();
                $run->update([
                    'status' => 'failed',
                    'report' => $report,
                    'finished_at' => now(),
                ]);
                throw $e;
            }

            return $report;
        });
    }

    /**
     * @param  array<string, mixed>  $member
     * @param  array<string, mixed>  $report
     */
    private function importMember(array $member, bool $dryRun, array &$report): void
    {
        $email = GhostMembersCsv::normalizeEmail($member['email'] ?? null);
        if (! $email) {
            $report['warnings'][] = 'Invalid email skipped.';

            return;
        }

        $report['members']['seen']++;
        $optedOut = filled($member['deleted_at'] ?? null)
            || ! GhostMembersCsv::truthy($member['subscribed_to_emails'] ?? $member['subscribed'] ?? true);
        $exists = MailingContact::query()->where('email', $email)->exists();
        $report['members'][$exists ? 'contacts_matched' : 'contacts_created']++;

        if ($dryRun) {
            if ($optedOut && ! Suppression::query()->where('email', $email)->exists()) {
                $report['suppressions']++;
            }

            return;
        }

        DB::transaction(function () use ($email, $optedOut, $member, &$report) {
            $contact = MailingContact::query()->firstOrCreate(['email' => $email]);

            if (! $optedOut) {
                return;
            }

            $suppression = Suppression::query()->firstOrCreate(
                ['email' => $email],
                ['reason' => 'ghost_import', 'notes' => 'Imported unsubscribed/deleted Ghost member'],
            );

            if (! $suppression->wasRecentlyCreated) {
                return;
            }

            $report['suppressions']++;
            ConsentEvent::create([
                'mailing_contact_id' => $contact->id,
                'email' => $email,
                'list' => null,
                'action' => ConsentAction::Suppressed,
                'source' => GhostNewsletterReconciler::SOURCE,
                'wording_version' => GhostNewsletterReconciler::WORDING_VERSION,
                'evidence' => ['source' => GhostNewsletterReconciler::SOURCE, 'created_at' => $member['created_at'] ?? null],
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => now(),
            ]);
            $report['consent_events']++;
        });
    }
}
