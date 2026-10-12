<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MagicLinkToken;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enforces docs/governance/retention-schedule.md for security records.
 */
class PurgeSecurityDataCommand extends Command
{
    protected $signature = 'newsroom:purge-security-data';

    protected $description = 'Delete audit logs and spent sign-in tokens past their retention period';

    public function handle(): int
    {
        $auditCutoff = now()->subMonths(max(1, (int) config('security.audit_retention_months', 24)));
        $tokenCutoff = now()->subDays(max(1, (int) config('security.magic_link_token_retention_days', 7)));

        $auditLogs = AuditLog::query()->where('created_at', '<', $auditCutoff)->delete();

        $magicLinks = MagicLinkToken::query()
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<', $tokenCutoff)
                ->orWhere('used_at', '<', $tokenCutoff))
            ->delete();

        $this->info("Purged {$auditLogs} audit log(s) and {$magicLinks} magic link token(s).");

        return self::SUCCESS;
    }
}
