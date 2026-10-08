<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Exceptions\Auth\LdapUnreachableException;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Reconciles a Cloudron OIDC identity into a local staff User record,
 * deriving the user's Role from their LDAP group membership.
 *
 * Fail-closed by design: any failure or ambiguity in the LDAP lookup
 * denies the login outright rather than granting or preserving an
 * elevated role. See docs/epic-1-build-plan.md issue #4.
 */
class StaffReconciler
{
    private const ADMIN_REVIEW_MESSAGE = 'Staff sign-in failed: this account needs to be linked by an administrator. Please contact the newsroom team.';

    public function __construct(
        private readonly LdapGroupLookup $ldap,
        private readonly UserSessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function reconcile(StaffOidcIdentity $identity): StaffReconcileResult
    {
        try {
            $groups = $this->ldap->groupsForEmail($identity->email);
        } catch (LdapUnreachableException) {
            return StaffReconcileResult::deny(
                'Staff sign-in failed: directory is currently unreachable. Please try again shortly.'
            );
        }

        $matchedRoles = $this->matchRoles($groups);

        if ($matchedRoles === []) {
            return StaffReconcileResult::deny(
                'Staff sign-in failed: your account is not a member of any recognised staff group.'
            );
        }

        $role = collect($matchedRoles)->sortByDesc(fn (Role $role) => $role->rank())->first();

        $user = User::query()->where('external_id', $identity->sub)->first();

        if ($user !== null) {
            $emailTakenByAnotherAccount = $user->email !== $identity->email
                && User::query()->where('email', $identity->email)->whereKeyNot($user->getKey())->exists();

            if ($emailTakenByAnotherAccount) {
                return StaffReconcileResult::deny(self::ADMIN_REVIEW_MESSAGE);
            }
        } else {
            // An existing account with the same email (any auth_provider) is
            // linked rather than inserted, but only when both the directory
            // and the local account have verified that email.
            $user = User::query()->where('email', $identity->email)->first();

            if ($user !== null && (! $identity->emailVerified || $user->email_verified_at === null)) {
                return StaffReconcileResult::deny(self::ADMIN_REVIEW_MESSAGE);
            }
        }

        $user ??= new User;
        $existed = $user->exists;
        $linked = $existed && ($user->auth_provider !== 'cloudron_oidc' || $user->external_id !== $identity->sub);
        $previousRole = $existed ? $user->role : null;

        $user->forceFill([
            'external_id' => $identity->sub,
            'name' => $identity->name,
            'email' => $identity->email,
            'email_verified_at' => now(),
            'password' => null,
            'auth_provider' => 'cloudron_oidc',
            'role' => $role,
            'ldap_group_snapshot' => $groups,
        ])->save();

        $promoted = $previousRole !== null && $role->rank() > $previousRole->rank();

        if ($linked || $promoted) {
            $this->sessions->revoke($user);
        }

        if ($linked) {
            $this->audit->record(null, 'staff.account_linked', $user, [
                'external_id' => $identity->sub,
            ]);
        }

        if ($previousRole !== null && $previousRole !== $role) {
            $this->audit->record(null, 'staff.role_changed', $user, [
                'from' => $previousRole->value,
                'to' => $role->value,
                'source' => 'oidc_login',
            ]);
        }

        return StaffReconcileResult::allow($user);
    }

    /**
     * @param  array<int, string>  $groups
     * @return array<int, Role>
     */
    private function matchRoles(array $groups): array
    {
        $map = collect(config('rbac.ldap_group_map', []))
            ->mapWithKeys(fn (Role $role, string $key) => [mb_strtolower($key) => $role]);

        return collect($groups)
            ->flatMap(fn (string $group) => $this->groupLookupKeys($group))
            ->map(fn (string $key) => $map->get($key))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build case-insensitive lookup keys for a memberof value.
     *
     * Cloudron returns full DNs (`cn=newsroom.staff,ou=groups,dc=cloudron`);
     * local OpenLDAP tests often pass bare CNs. Match both the raw value
     * and the CN RDN when present.
     *
     * @return array<int, string>
     */
    private function groupLookupKeys(string $group): array
    {
        $normalized = mb_strtolower($group);
        $keys = [$normalized];

        if (preg_match('/(^|,)cn=([^,]+)/i', $group, $matches) === 1) {
            $keys[] = mb_strtolower($matches[2]);
        }

        return array_values(array_unique($keys));
    }
}
