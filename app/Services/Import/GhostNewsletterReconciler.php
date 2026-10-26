<?php

namespace App\Services\Import;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\ConsentEvent;
use App\Models\ImportRun;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets each Ghost member's Newsroom newsletter subscriptions from Ghost's
 * per-newsletter exports. Never sends mail, never re-subscribes an opt-out,
 * and only removes rows whose entire consent history is the Ghost import.
 */
class GhostNewsletterReconciler
{
    use SuppressesOutboundMail;

    public const RUN_TYPE = 'ghost_newsletters_csv';

    public const SOURCE = 'ghost_members_csv';

    public const WORDING_VERSION = 'ghost-import-v2';

    private const SAMPLE_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $directory,
        bool $apply = false,
        ?User $actor = null,
        ?ImportRun $fromRun = null,
        bool $acceptMismatch = false,
    ): array {
        return $this->withoutOutboundMail(function () use ($directory, $apply, $actor, $fromRun, $acceptMismatch) {
            $directory = rtrim($directory, '/\\');
            if (! is_dir($directory)) {
                throw new RuntimeException('Ghost export directory not found.');
            }

            $checksum = $this->combinedChecksum($directory);

            if ($apply) {
                $this->assertApplicable($fromRun, $checksum, $acceptMismatch);
            }

            $run = ImportRun::create([
                'type' => self::RUN_TYPE,
                'status' => 'running',
                'dry_run' => ! $apply,
                'source_path' => $directory,
                'source_checksum' => $checksum,
                'actor_id' => $actor?->id,
                'started_at' => now(),
            ]);

            try {
                $plan = $this->plan($directory);
                $report = $plan['report'];
                $report['mode'] = $apply ? 'apply' : 'dry-run';
                $report['import_run_id'] = $run->id;

                if ($apply) {
                    $report['from_run_id'] = $fromRun?->id;
                    DB::transaction(fn () => $this->execute($plan, $run));
                    $report['confirmed_after_actual'] = $this->confirmedCounts($plan['mappings']);
                }

                $run->update(['status' => 'completed', 'report' => $report, 'finished_at' => now()]);

                return $report;
            } catch (\Throwable $e) {
                $run->update([
                    'status' => 'failed',
                    'report' => ['problems' => [$e->getMessage()]],
                    'finished_at' => now(),
                ]);
                throw $e;
            }
        });
    }

    private function assertApplicable(?ImportRun $fromRun, string $checksum, bool $acceptMismatch): void
    {
        if (! $fromRun || $fromRun->type !== self::RUN_TYPE || ! $fromRun->dry_run || $fromRun->status !== 'completed') {
            throw new RuntimeException('Apply needs a completed dry run of the same exports (--from-run).');
        }

        if ($fromRun->source_checksum !== $checksum) {
            throw new RuntimeException('The export files changed since that dry run. Run a new dry run first.');
        }

        $report = $fromRun->report ?? [];
        if (($report['problems'] ?? []) !== []) {
            throw new RuntimeException('That dry run reported problems; fix them and run a new dry run.');
        }

        if (! ($report['reconciled'] ?? false) && ! $acceptMismatch) {
            throw new RuntimeException('That dry run did not match the expected Ghost counts. Pass --accept-mismatch only after sign-off.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mappings(): array
    {
        return array_values(config('ghost_newsletters.newsletters', []));
    }

    private function membersFile(string $directory): string
    {
        return $directory.DIRECTORY_SEPARATOR.config('ghost_newsletters.members_file', 'members.csv');
    }

    private function newsletterFile(string $directory, string $key): string
    {
        return $directory.DIRECTORY_SEPARATOR.$key.'.csv';
    }

    private function combinedChecksum(string $directory): string
    {
        $parts = [];
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.csv') ?: [] as $file) {
            $parts[basename($file)] = hash_file('sha256', $file);
        }
        ksort($parts);

        return hash('sha256', json_encode($parts));
    }

    /**
     * @return array{report: array<string, mixed>, mappings: list<array<string, mixed>>, actions: array<string, mixed>}
     */
    private function plan(string $directory): array
    {
        $problems = [];
        $warnings = [];

        $membersPath = $this->membersFile($directory);
        if (! is_file($membersPath)) {
            throw new RuntimeException('The full Ghost members export ('.basename($membersPath).') is missing.');
        }

        $members = [];
        $invalid = 0;
        foreach (GhostMembersCsv::rows($membersPath) as $row) {
            $email = GhostMembersCsv::normalizeEmail($row['email'] ?? null);
            if (! $email) {
                $invalid++;

                continue;
            }
            $members[$email] = [
                'opted_out' => filled($row['deleted_at'] ?? null)
                    || ! GhostMembersCsv::truthy($row['subscribed_to_emails'] ?? $row['subscribed'] ?? true),
            ];
        }
        if ($invalid > 0) {
            $warnings[] = $invalid.' row(s) in the members export had no valid email and were skipped.';
        }

        $mappings = [];
        $files = ['members' => ['file' => basename($membersPath), 'rows' => count($members), 'checksum' => hash_file('sha256', $membersPath)]];
        $knownFiles = [basename($membersPath)];

        foreach ($this->mappings() as $mapping) {
            $key = (string) $mapping['key'];
            $knownFiles[] = $key.'.csv';
            $newsletter = Newsletter::query()->where('slug', $mapping['newsroom_slug'])->first();
            if (! $newsletter) {
                $problems[] = 'Newsroom newsletter "'.$mapping['newsroom_slug'].'" for Ghost "'.$mapping['ghost_name'].'" does not exist.';
            } elseif ($newsletter->status() !== $mapping['status']) {
                $warnings[] = 'Newsroom newsletter "'.$newsletter->slug.'" is '.$newsletter->status().' but Ghost "'.$mapping['ghost_name'].'" is '.$mapping['status'].'.';
            }

            $path = $this->newsletterFile($directory, $key);
            $emails = [];
            if (is_file($path)) {
                foreach (GhostMembersCsv::rows($path) as $row) {
                    $email = GhostMembersCsv::normalizeEmail($row['email'] ?? null);
                    if ($email) {
                        $emails[$email] = true;
                    }
                }
                $files['newsletters'][$key] = ['file' => $key.'.csv', 'present' => true, 'rows' => count($emails), 'checksum' => hash_file('sha256', $path)];
            } else {
                $files['newsletters'][$key] = ['file' => $key.'.csv', 'present' => false, 'rows' => 0, 'checksum' => null];
                $problems[] = 'Missing export for Ghost newsletter "'.$mapping['ghost_name'].'" ('.$key.'.csv).';
            }

            $mappings[] = [...$mapping, 'newsletter' => $newsletter, 'emails' => $emails, 'present' => is_file($path)];
        }

        $unmapped = array_values(array_diff(
            array_map('basename', glob($directory.DIRECTORY_SEPARATOR.'*.csv') ?: []),
            $knownFiles,
        ));
        foreach ($unmapped as $file) {
            $problems[] = 'Export "'.$file.'" does not match any mapped Ghost newsletter.';
        }

        $universe = array_keys($members);
        foreach ($mappings as $mapping) {
            $universe = array_merge($universe, array_keys($mapping['emails']));
        }
        $universe = array_values(array_unique($universe));

        $contacts = $this->contactsByEmail($universe);
        $events = $this->eventsByContact($contacts);
        $suppressed = array_fill_keys(
            Suppression::query()->pluck('email')->map(fn ($email) => strtolower((string) $email))->all(),
            true,
        );

        $optOuts = array_keys(array_filter($members, fn ($member) => $member['opted_out']));
        $actions = [
            'contacts' => [],
            'suppress' => array_values(array_filter($optOuts, fn ($email) => ! isset($suppressed[$email]))),
            'confirm' => [],
            'link' => [],
            'remove' => [],
        ];

        $newsletterReports = [];
        $samples = [];

        foreach ($mappings as $index => $mapping) {
            $newsletter = $mapping['newsletter'];
            $key = (string) $mapping['key'];
            $counts = [
                'ghost_name' => $mapping['ghost_name'],
                'newsroom_slug' => $mapping['newsroom_slug'],
                'status' => $mapping['status'],
                'expected' => (int) $mapping['expected'],
                'in_export' => count($mapping['emails']),
                'matches_expected' => count($mapping['emails']) === (int) $mapping['expected'],
                'to_confirm' => 0,
                'already_confirmed' => 0,
                'blocked' => ['ghost_opt_out' => 0, 'suppressed' => 0, 'newsroom_unsubscribed' => 0],
                'to_remove' => 0,
                'newsroom_choices_kept' => 0,
                'not_in_members_export' => 0,
            ];

            if (! $newsletter || ! $mapping['present']) {
                $newsletterReports[$key] = [...$counts, 'skipped' => true];

                continue;
            }

            $confirmedBefore = $this->confirmedQuery($newsletter)->count();
            $removedConfirmed = 0;

            foreach ($universe as $email) {
                $contact = $contacts[$email] ?? null;
                $row = $contact ? $this->rowFor($contact, $newsletter) : null;
                $inFile = isset($mapping['emails'][$email]);

                if ($inFile) {
                    if (! isset($members[$email])) {
                        $counts['not_in_members_export']++;
                    }

                    $reason = match (true) {
                        $members[$email]['opted_out'] ?? false => 'ghost_opt_out',
                        isset($suppressed[$email]) => 'suppressed',
                        $row?->status === SubscriptionStatus::Unsubscribed => 'newsroom_unsubscribed',
                        default => null,
                    };

                    if ($reason !== null) {
                        $counts['blocked'][$reason]++;
                        $this->sample($samples, $key.'.'.$reason, $email);
                    } elseif ($row?->status === SubscriptionStatus::Confirmed) {
                        $counts['already_confirmed']++;
                        if ($row->newsletter_id !== $newsletter->id) {
                            $actions['link'][] = [$row->id, $newsletter->id];
                        }
                    } else {
                        $counts['to_confirm']++;
                        if (! $contact) {
                            $actions['contacts'][$email] = true;
                        }
                        $actions['confirm'][] = ['email' => $email, 'mapping' => $index, 'row' => $row?->id];
                    }

                    continue;
                }

                if (! $row || $row->status === SubscriptionStatus::Unsubscribed) {
                    continue;
                }

                if ($this->isGhostImportOnly($row, $events[$contact->id] ?? collect())) {
                    $counts['to_remove']++;
                    if ($row->status === SubscriptionStatus::Confirmed) {
                        $removedConfirmed++;
                    }
                    $actions['remove'][] = ['row' => $row->id, 'mapping' => $index];
                } else {
                    $counts['newsroom_choices_kept']++;
                }
            }

            $counts['confirmed_before'] = $confirmedBefore;
            $counts['confirmed_after'] = $confirmedBefore - $removedConfirmed + $counts['to_confirm'];
            $newsletterReports[$key] = $counts;
        }

        $reconciled = $problems === [] && collect($newsletterReports)->every(fn ($counts) => $counts['matches_expected']);

        return [
            'mappings' => $mappings,
            'actions' => $actions,
            'report' => [
                'files' => $files,
                'members' => [
                    'seen' => count($members),
                    'opted_out' => count($optOuts),
                    'contacts_to_create' => count($actions['contacts']),
                    'suppressions_to_create' => count($actions['suppress']),
                ],
                'newsletters' => $newsletterReports,
                'samples' => $samples,
                'reconciled' => $reconciled,
                'problems' => $problems,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @param  array{mappings: list<array<string, mixed>>, actions: array<string, mixed>}  $plan
     */
    private function execute(array $plan, ImportRun $run): void
    {
        $evidence = fn (array $mapping) => [
            'source' => self::SOURCE,
            'ghost_newsletter' => $mapping['key'],
            'ghost_id' => $mapping['ghost_id'],
            'newsletter_id' => $mapping['newsletter']->id,
            'import_run_id' => $run->id,
        ];

        foreach (array_keys($plan['actions']['contacts']) as $email) {
            MailingContact::query()->firstOrCreate(['email' => $email]);
        }

        foreach ($plan['actions']['suppress'] as $email) {
            $suppression = Suppression::query()->firstOrCreate(
                ['email' => $email],
                ['reason' => 'ghost_import', 'notes' => 'Ghost member unsubscribed from all emails or deleted'],
            );
            if ($suppression->wasRecentlyCreated) {
                $contact = MailingContact::query()->where('email', $email)->first();
                $this->event($contact, $email, null, ConsentAction::Suppressed, ['source' => self::SOURCE, 'import_run_id' => $run->id]);
            }
        }

        foreach ($plan['actions']['confirm'] as $action) {
            $mapping = $plan['mappings'][$action['mapping']];
            /** @var Newsletter $newsletter */
            $newsletter = $mapping['newsletter'];
            $contact = MailingContact::query()->where('email', $action['email'])->firstOrFail();
            $legacy = $newsletter->legacy_list;

            $subscription = $action['row']
                ? MailingListSubscription::query()->findOrFail($action['row'])
                : new MailingListSubscription(['mailing_contact_id' => $contact->id]);

            $subscription->fill([
                'newsletter_id' => $newsletter->id,
                'list' => $legacy?->value,
                'status' => SubscriptionStatus::Confirmed,
                'confirm_token' => null,
                'confirmed_at' => $subscription->confirmed_at ?? now(),
                'unsubscribed_at' => null,
            ])->save();

            $this->event($contact, $action['email'], $legacy, ConsentAction::Confirmed, $evidence($mapping));
        }

        foreach ($plan['actions']['link'] as [$rowId, $newsletterId]) {
            MailingListSubscription::query()->whereKey($rowId)->update(['newsletter_id' => $newsletterId]);
        }

        foreach ($plan['actions']['remove'] as $action) {
            $mapping = $plan['mappings'][$action['mapping']];
            $subscription = MailingListSubscription::query()->with('contact')->find($action['row']);
            if (! $subscription) {
                continue;
            }

            $this->event(
                $subscription->contact,
                (string) $subscription->contact?->email,
                $subscription->list,
                ConsentAction::PreferenceUpdated,
                [...$evidence($mapping), 'removed' => 'not_subscribed_in_ghost', 'previous_status' => $subscription->status->value],
            );
            $subscription->delete();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     * @return array<string, int>
     */
    private function confirmedCounts(array $mappings): array
    {
        $out = [];
        foreach ($mappings as $mapping) {
            if ($mapping['newsletter']) {
                $out[$mapping['key']] = $this->confirmedQuery($mapping['newsletter'])->count();
            }
        }

        return $out;
    }

    /**
     * @return Builder<MailingListSubscription>
     */
    private function confirmedQuery(Newsletter $newsletter): Builder
    {
        return MailingListSubscription::query()
            ->where('status', SubscriptionStatus::Confirmed)
            ->where(function ($query) use ($newsletter) {
                $query->where('newsletter_id', $newsletter->id);
                if ($newsletter->legacy_list instanceof MailingList) {
                    $query->orWhere('list', $newsletter->legacy_list->value);
                }
            });
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, MailingContact>
     */
    private function contactsByEmail(array $emails): array
    {
        $out = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            MailingContact::query()->with('subscriptions')->whereIn('email', $chunk)->get()
                ->each(function (MailingContact $contact) use (&$out) {
                    $out[strtolower($contact->email)] = $contact;
                });
        }

        return $out;
    }

    /**
     * @param  array<string, MailingContact>  $contacts
     * @return array<int, Collection<int, ConsentEvent>>
     */
    private function eventsByContact(array $contacts): array
    {
        $ids = array_map(fn (MailingContact $contact) => $contact->id, array_values($contacts));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            ConsentEvent::query()->whereIn('mailing_contact_id', $chunk)->get()
                ->groupBy('mailing_contact_id')
                ->each(function (Collection $events, $contactId) use (&$out) {
                    $out[(int) $contactId] = $events;
                });
        }

        return $out;
    }

    private function rowFor(MailingContact $contact, Newsletter $newsletter): ?MailingListSubscription
    {
        $legacy = $newsletter->legacy_list;

        return $contact->subscriptions->first(
            fn (MailingListSubscription $row) => $row->newsletter_id === $newsletter->id
                || ($legacy instanceof MailingList && $row->list === $legacy),
        );
    }

    /**
     * True only when the row has consent history and all of it came from the Ghost import.
     *
     * @param  Collection<int, ConsentEvent>  $events
     */
    private function isGhostImportOnly(MailingListSubscription $row, Collection $events): bool
    {
        $matching = $events->filter(function (ConsentEvent $event) use ($row) {
            if ($row->list !== null && $event->list === $row->list) {
                return true;
            }

            return $row->newsletter_id !== null
                && (int) ($event->evidence['newsletter_id'] ?? 0) === $row->newsletter_id;
        });

        return $matching->isNotEmpty() && $matching->every(fn (ConsentEvent $event) => $event->source === self::SOURCE);
    }

    /**
     * @param  array<string, list<string>>  $samples
     */
    private function sample(array &$samples, string $bucket, string $email): void
    {
        if (count($samples[$bucket] ?? []) < self::SAMPLE_LIMIT) {
            $samples[$bucket][] = GhostMembersCsv::maskEmail($email);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function event(?MailingContact $contact, string $email, ?MailingList $list, ConsentAction $action, array $evidence): void
    {
        ConsentEvent::create([
            'mailing_contact_id' => $contact?->id,
            'email' => $email,
            'list' => $list?->value,
            'action' => $action,
            'source' => self::SOURCE,
            'wording_version' => self::WORDING_VERSION,
            'evidence' => $evidence,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }
}
