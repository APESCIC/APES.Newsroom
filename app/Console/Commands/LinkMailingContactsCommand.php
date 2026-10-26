<?php

namespace App\Console\Commands;

use App\Models\MailingContact;
use App\Models\User;
use App\Services\Mailing\LinkMailingContactService;
use App\Support\EmailAddress;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Backfill mailing contact ↔ verified user links (#508).
 */
class LinkMailingContactsCommand extends Command
{
    protected $signature = 'newsroom:link-mailing-contacts
        {--dry-run : Report matches, conflicts and skips without writing}';

    protected $description = 'Link mailing contacts to users with the same verified email';

    public function handle(LinkMailingContactService $links): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $matched = 0;
        $already = 0;
        $conflicts = 0;
        $skippedUnverified = 0;
        $created = 0;

        $users = User::query()
            ->orderBy('id')
            ->get(['id', 'email', 'email_verified_at', 'name']);

        foreach ($users as $user) {
            if ($user->email_verified_at === null) {
                $skippedUnverified++;
                $this->line("skip unverified: {$user->email}");

                continue;
            }

            $email = EmailAddress::normalize((string) $user->email);
            $contact = MailingContact::query()->forNormalizedEmail($email)->first();

            if ($contact !== null && $contact->user_id !== null && (int) $contact->user_id !== (int) $user->id) {
                $conflicts++;
                $this->warn("conflict: {$email} linked to user #{$contact->user_id}, not #{$user->id}");

                continue;
            }

            if ($contact !== null && (int) $contact->user_id === (int) $user->id) {
                $already++;

                continue;
            }

            if ($dryRun) {
                $matched++;
                $this->line(($contact ? 'would link' : 'would create+link').": {$email} → user #{$user->id}");

                continue;
            }

            try {
                $result = $links->link($user);
            } catch (RuntimeException $e) {
                $conflicts++;
                $this->warn("conflict: {$email} — {$e->getMessage()}");

                continue;
            }

            match ($result->outcome) {
                'created' => $created++,
                'linked' => $matched++,
                'already_linked' => $already++,
                default => null,
            };
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['mode', $dryRun ? 'dry-run' : 'apply'],
                ['matched / linked', $matched],
                ['created', $created],
                ['already linked', $already],
                ['conflicts', $conflicts],
                ['skipped unverified', $skippedUnverified],
            ],
        );

        return self::SUCCESS;
    }
}
