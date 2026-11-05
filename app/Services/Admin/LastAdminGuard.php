<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Keeps at least one unlocked admin. Call inside a transaction: every admin
 * row stays locked until it commits, so two admins acting on each other at
 * the same moment are applied one after the other and the second is refused.
 */
final class LastAdminGuard
{
    public static function ensureAnotherAdmin(User $user, string $field, string $message): void
    {
        if ($user->role !== Role::Admin) {
            return;
        }

        $others = User::query()
            ->where('role', Role::Admin)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'locked_at'])
            ->reject(fn (User $admin) => $admin->id === $user->id || $admin->locked_at !== null);

        if ($others->isEmpty()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
