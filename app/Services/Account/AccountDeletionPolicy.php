<?php

namespace App\Services\Account;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\ImportRun;
use App\Models\ModerationAudit;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\User;

class AccountDeletionPolicy
{
    public function blockingReason(User $user): ?string
    {
        if ($user->role !== Role::Public) {
            return 'Staff and administrator accounts require an administrator-led retention and erasure process.';
        }

        if (! in_array($user->auth_provider, [null, 'password'], true)) {
            return 'Directory-managed accounts require an administrator-led retention and erasure process.';
        }

        if ($this->hasProtectedRecords($user)) {
            return 'This account owns protected editorial or audit records and requires an administrator-led retention and erasure process.';
        }

        return null;
    }

    private function hasProtectedRecords(User $user): bool
    {
        return Post::query()->where('author_id', $user->id)->exists()
            || PostRevision::query()->where('editor_id', $user->id)->exists()
            || Campaign::query()->where('created_by', $user->id)->exists()
            || AuditLog::query()->where('actor_id', $user->id)->whereNot(fn ($query) => $query->selfService())->exists()
            || ModerationAudit::query()->where('actor_id', $user->id)->exists()
            || ImportRun::query()->where('actor_id', $user->id)->exists();
    }
}
