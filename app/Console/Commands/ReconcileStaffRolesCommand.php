<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Exceptions\Auth\LdapUnreachableException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LdapGroupLookup;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Console\Command;

/**
 * Scheduled safety reconciliation for Cloudron staff accounts (issue #4).
 *
 * Re-queries LDAP group membership for every staff user and downgrades
 * or demotes accounts whose directory membership no longer matches.
 */
class ReconcileStaffRolesCommand extends Command
{
    protected $signature = 'staff:reconcile-roles';

    protected $description = 'Reconcile Cloudron staff roles from current LDAP group membership';

    public function handle(LdapGroupLookup $ldap, UserSessionRevoker $sessions, AuditLogger $audit): int
    {
        $staffUsers = User::query()
            ->where('auth_provider', 'cloudron_oidc')
            ->whereNotNull('external_id')
            ->get();

        $map = collect(config('rbac.ldap_group_map', []))
            ->mapWithKeys(fn (Role $role, string $group) => [mb_strtolower($group) => $role]);

        $updated = 0;
        $demoted = 0;

        foreach ($staffUsers as $user) {
            try {
                $groups = $ldap->groupsForEmail($user->email);
            } catch (LdapUnreachableException $e) {
                $this->warn("LDAP unreachable; skipping {$user->email}");

                continue;
            }

            $matchedRoles = collect($groups)
                ->flatMap(function (string $group) use ($map) {
                    $keys = [mb_strtolower($group)];
                    if (preg_match('/(^|,)cn=([^,]+)/i', $group, $matches) === 1) {
                        $keys[] = mb_strtolower($matches[2]);
                    }

                    return collect($keys)->map(fn (string $key) => $map->get($key));
                })
                ->filter()
                ->unique()
                ->values();

            if ($matchedRoles->isEmpty()) {
                $previousRole = $user->role;

                $user->forceFill([
                    'role' => Role::Public,
                    'ldap_group_snapshot' => $groups,
                ])->save();

                if ($previousRole !== Role::Public) {
                    $this->recordRoleChange($user, $previousRole, $sessions, $audit);
                }

                $demoted++;
                $this->line("Demoted {$user->email} — no recognised LDAP groups");

                continue;
            }

            $role = $matchedRoles->sortByDesc(fn (Role $role) => $role->rank())->first();

            if ($user->role !== $role || $user->ldap_group_snapshot !== $groups) {
                $previousRole = $user->role;

                $user->forceFill([
                    'role' => $role,
                    'ldap_group_snapshot' => $groups,
                ])->save();

                if ($previousRole !== $role) {
                    $this->recordRoleChange($user, $previousRole, $sessions, $audit);
                }

                $updated++;
                $this->line("Updated {$user->email} → {$role->value}");
            }
        }

        $this->info("Reconciliation complete: {$updated} updated, {$demoted} demoted.");

        return self::SUCCESS;
    }

    private function recordRoleChange(User $user, Role $previousRole, UserSessionRevoker $sessions, AuditLogger $audit): void
    {
        $sessions->revoke($user);

        $revokedTokens = $user->role->atLeast(Role::Staff) ? 0 : $user->apiTokens()->delete();

        $audit->record(null, 'staff.role_changed', $user, [
            'from' => $previousRole->value,
            'to' => $user->role->value,
            'source' => 'staff:reconcile-roles',
            'api_tokens_revoked' => $revokedTokens,
        ]);
    }
}
