<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Demo accounts created by DemoUsersSeeder for local role preview. They
 * share a known password, so none may exist outside local and testing.
 * Other @apes.local addresses (e.g. the Ghost import fallback author) use
 * random passwords and are not demo accounts.
 */
final class DemoAccounts
{
    public const EMAILS = [
        'public@apes.local',
        'staff@apes.local',
        'admin@apes.local',
        'superadmin@apes.local',
    ];

    /**
     * @return Builder<User>
     */
    public static function query(): Builder
    {
        return User::query()->whereIn('email', self::EMAILS);
    }
}
