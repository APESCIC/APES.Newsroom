<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Demo accounts created by DemoUsersSeeder for local role preview. They
 * share a known password, so none may exist outside local and testing.
 */
final class DemoAccounts
{
    public const EMAIL_DOMAIN = 'apes.local';

    /**
     * @return Builder<User>
     */
    public static function query(): Builder
    {
        return User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN);
    }
}
