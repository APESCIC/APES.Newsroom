<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\EmailChangeRequest;
use App\Models\MagicLinkToken;
use App\Models\UserSession;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enforces docs/governance/retention-schedule.md for security records.
 */
class PurgeSecurityDataCommand extends Command
{
    protected $signature = 'newsroom:purge-security-data';

    protected $description = 'Delete audit logs, spent sign-in and email change tokens, and old session records past their retention period';

    public function handle(): int
    {
        $auditCutoff = now()->subMonths(max(1, (int) config('security.audit_retention_months', 24)));
        $tokenCutoff = now()->subDays(max(1, (int) config('security.magic_link_token_retention_days', 7)));
        $sessionCutoff = now()->subDays(max(1, (int) config('security.session_record_retention_days', 90)));

        $auditLogs = AuditLog::query()->where('created_at', '<', $auditCutoff)->delete();

        $magicLinks = MagicLinkToken::query()
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<', $tokenCutoff)
                ->orWhere('used_at', '<', $tokenCutoff))
            ->delete();

        $emailChanges = EmailChangeRequest::query()->where('expires_at', '<', $tokenCutoff)->delete();

        $sessions = UserSession::query()
            ->where(fn (Builder $query) => $query
                ->where('revoked_at', '<', $sessionCutoff)
                ->orWhere('last_seen_at', '<', $sessionCutoff)
                ->orWhere(fn (Builder $never) => $never
                    ->whereNull('last_seen_at')
                    ->where('created_at', '<', $sessionCutoff)))
            ->delete();

        $this->info("Purged {$auditLogs} audit log(s), {$magicLinks} magic link token(s), {$emailChanges} email change request(s) and {$sessions} session record(s).");

        return self::SUCCESS;
    }
}
