<?php

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * Single source for the password rule used by registration, reset and
 * change. Values come from config/security.php for now; v1.11.0 security
 * policy settings can replace this lookup without touching callers.
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min(max(8, (int) config('security.password.min_length', 12)))
            ->max(max(64, (int) config('security.password.max_length', 128)));

        if (config('security.password.check_breached')) {
            $rule->uncompromised();
        }

        return $rule;
    }
}
