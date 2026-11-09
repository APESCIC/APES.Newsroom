<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Demo accounts created by DemoUsersSeeder for local role preview. They
 * share a known password, so none may exist outside local and testing.
 * LEGACY_EMAILS are the addresses used before v1.30.3; the deploy check
 * still looks for them. Other @apes.local addresses (e.g. the Ghost import
 * fallback author) use random passwords and are not demo accounts.
 */
final class DemoAccounts
{
    public const PASSWORD = 'demo-local-only';

    public const ADMIN = 'admin@example.test';

    public const STAFF = 'staff@example.test';

    public const MEMBER = 'member@example.test';

    public const EMAILS = [
        self::MEMBER,
        self::STAFF,
        self::ADMIN,
    ];

    public const LEGACY_EMAILS = [
        'public@apes.local',
        'staff@apes.local',
        'admin@apes.local',
    ];

    /**
     * @return Builder<User>
     */
    public static function query(): Builder
    {
        return User::query()->whereIn('email', [...self::EMAILS, ...self::LEGACY_EMAILS]);
    }
}
