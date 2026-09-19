<?php

namespace App\Services\Import;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\ConsentEvent;
use App\Models\ImportRun;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class GhostMembersCsvImporter
{
    use SuppressesOutboundMail;

    /**
     * Labels that constitute evidence for all-three-list activation.
     *
     * @var list<string>
     */
    private const ALL_LISTS_EVIDENCE_LABELS = [
        'apes-all',
        'all-lists',
        'apes-cic,apes-shelter-rescue,apes-pet-care-clinic',
    ];

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
                'members' => ['seen' => 0, 'contacts_created' => 0, 'contacts_updated' => 0],
                'subscriptions' => ['activated' => 0, 'held_for_reconfirm' => 0, 'unsubscribed' => 0],
                'consent_events' => 0,
                'suppressions' => 0,
                'warnings' => [],
            ];

            try {
                $handle = fopen($csvPath, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Unable to open CSV.');
                }

                $headers = fgetcsv($handle);
                if ($headers === false) {
                    throw new RuntimeException('CSV is empty.');
                }

                $headers = array_map(fn ($h) => Str::of((string) $h)->lower()->trim()->toString(), $headers);
                $this->assertHeaders($headers);

                while (($row = fgetcsv($handle)) !== false) {
                    if ($this->rowIsEmpty($row)) {
                        continue;
                    }

                    $member = array_combine($headers, array_pad($row, count($headers), null));
                    if ($member === false) {
                        $report['warnings'][] = 'Malformed CSV row skipped.';

                        continue;
                    }

                    $this->importMember($member, $dryRun, $report);
                }

                fclose($handle);

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
     * @param  list<string|null>  $headers
     */
    private function assertHeaders(array $headers): void
    {
        if (! in_array('email', $headers, true)) {
            throw new RuntimeException('CSV must include an email column.');
        }
    }

    /**
     * @param  list<string|null>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        return collect($row)->every(fn ($cell) => trim((string) $cell) === '');
    }

    /**
     * @param  array<string, mixed>  $member
     * @param  array<string, mixed>  $report
     */
    private function importMember(array $member, bool $dryRun, array &$report): void
    {
        $email = strtolower(trim((string) ($member['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $report['warnings'][] = 'Invalid email skipped.';

            return;
        }

        $report['members']['seen']++;
        $subscribed = $this->truthy($member['subscribed_to_emails'] ?? $member['subscribed'] ?? true);
        $deleted = filled($member['deleted_at'] ?? null);
        $labels = $this->parseLabels((string) ($member['labels'] ?? $member['label'] ?? ''));
        $createdAt = ! empty($member['created_at']) ? $member['created_at'] : now()->toIso8601String();

        if ($dryRun) {
            if ($deleted || ! $subscribed) {
                $report['suppressions']++;
                $report['subscriptions']['unsubscribed']++;
            } elseif ($this->hasAllListsEvidence($labels)) {
                $report['subscriptions']['activated'] += 3;
                $report['consent_events'] += 3;
            } else {
                $report['subscriptions']['held_for_reconfirm'] += 3;
            }
            $report['members']['contacts_created']++;

            return;
        }

        DB::transaction(function () use ($email, $subscribed, $deleted, $labels, $createdAt, &$report) {
            $contact = MailingContact::query()->firstOrNew(['email' => $email]);
            $wasNew = ! $contact->exists;
            $contact->save();
            $report['members'][$wasNew ? 'contacts_created' : 'contacts_updated']++;

            if ($deleted || ! $subscribed) {
                Suppression::query()->firstOrCreate(
                    ['email' => $email],
                    ['reason' => 'ghost_import', 'notes' => 'Imported unsubscribed/deleted Ghost member'],
                );
                $report['suppressions']++;

                foreach (MailingList::cases() as $list) {
                    $subscription = MailingListSubscription::query()->firstOrNew([
                        'mailing_contact_id' => $contact->id,
                        'list' => $list,
                    ]);
                    $subscription->fill([
                        'status' => SubscriptionStatus::Unsubscribed,
                        'unsubscribed_at' => now(),
                        'confirm_token' => $subscription->confirm_token ?: Str::random(64),
                    ])->save();
                    $report['subscriptions']['unsubscribed']++;
                    $this->consent($contact, $email, $list, ConsentAction::Suppressed, [
                        'source' => 'ghost_members_csv',
                        'created_at' => $createdAt,
                    ]);
                    $report['consent_events']++;
                }

                return;
            }

            $activateAll = $this->hasAllListsEvidence($labels);

            foreach (MailingList::cases() as $list) {
                $subscription = MailingListSubscription::query()->firstOrNew([
                    'mailing_contact_id' => $contact->id,
                    'list' => $list,
                ]);

                if ($activateAll) {
                    $subscription->fill([
                        'status' => SubscriptionStatus::Confirmed,
                        'confirmed_at' => $subscription->confirmed_at ?? now(),
                        'unsubscribed_at' => null,
                        'confirm_token' => $subscription->confirm_token ?: Str::random(64),
                    ])->save();
                    $report['subscriptions']['activated']++;
                    $this->consent($contact, $email, $list, ConsentAction::Confirmed, [
                        'source' => 'ghost_members_csv',
                        'labels' => $labels,
                        'evidence' => 'all_lists_label',
                        'created_at' => $createdAt,
                    ]);
                } else {
                    // Fail closed: keep inactive / pending until reconfirmation.
                    if (! $subscription->exists || $subscription->status !== SubscriptionStatus::Confirmed) {
                        $subscription->fill([
                            'status' => SubscriptionStatus::Pending,
                            'confirmed_at' => null,
                            'confirm_token' => $subscription->confirm_token ?: Str::random(64),
                        ])->save();
                    }
                    $report['subscriptions']['held_for_reconfirm']++;
                    $this->consent($contact, $email, $list, ConsentAction::SignupRequested, [
                        'source' => 'ghost_members_csv',
                        'labels' => $labels,
                        'held' => true,
                        'created_at' => $createdAt,
                    ]);
                }
                $report['consent_events']++;
            }
        });
    }

    /**
     * @param  list<string>  $labels
     */
    private function hasAllListsEvidence(array $labels): bool
    {
        $normalized = array_map(fn ($label) => Str::of($label)->lower()->trim()->toString(), $labels);
        foreach (self::ALL_LISTS_EVIDENCE_LABELS as $evidence) {
            if (in_array($evidence, $normalized, true)) {
                return true;
            }
        }

        $required = ['apes-cic', 'apes-shelter-rescue', 'apes-pet-care-clinic'];

        return count(array_intersect($required, $normalized)) === 3;
    }

    /**
     * @return list<string>
     */
    private function parseLabels(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        // Ghost exports sometimes use JSON arrays; otherwise comma-separated.
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_map(fn ($v) => (string) (is_array($v) ? ($v['name'] ?? '') : $v), $decoded);
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 't', 'y'], true);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function consent(
        MailingContact $contact,
        string $email,
        MailingList $list,
        ConsentAction $action,
        array $evidence,
    ): void {
        ConsentEvent::create([
            'mailing_contact_id' => $contact->id,
            'email' => $email,
            'list' => $list,
            'action' => $action,
            'source' => 'ghost_members_csv',
            'wording_version' => 'ghost-import-v1',
            'evidence' => $evidence,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }
}
