<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserSessionRevoker;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * In-app role changes for accounts outside Cloudron SSO. Staff and admin
 * roles require SSO and come from directory groups, so the only role that
 * can be assigned here is Public.
 */
class UserRoleChanger
{
    public const ASSIGNABLE = [Role::Public];

    public function __construct(
        private readonly UserSessionRevoker $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function change(User $user, Role $role, User $actor, string $reason, Request $request): void
    {
        if (! in_array($role, self::ASSIGNABLE, true)) {
            throw ValidationException::withMessages([
                'role' => 'Staff and admin roles come from Cloudron directory groups. Add the person to the right group in Cloudron instead.',
            ]);
        }

        if ($user->role === $role) {
            throw ValidationException::withMessages(['role' => 'The account already has this role.']);
        }

        if ($user->role === Role::SuperAdmin && ! User::query()->where('role', Role::SuperAdmin)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'The last super admin cannot be demoted.']);
        }

        $from = $user->role;
        $user->forceFill(['role' => $role])->save();

        if (! $role->atLeast(Role::Staff)) {
            $user->apiTokens()->delete();
        }

        $this->sessions->revoke($user);

        $this->audit->record($actor, 'user.role_changed', $user, [
            'from' => $from->value,
            'to' => $role->value,
            'reason' => $reason,
        ], $request);
    }
}
