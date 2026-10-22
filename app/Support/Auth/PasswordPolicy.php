<?php

namespace App\Support\Auth;

use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Validation\Rules\Password;

/**
 * Single source for the password rule used by registration, reset and
 * change. Minimum length and the breach check come from the admin security
 * settings (defaults from config/security.php); the length never drops
 * below SettingDefinitions::PASSWORD_MIN_LENGTH_FLOOR.
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        $settings = app(SettingsRepository::class);
        $minimum = max(SettingDefinitions::PASSWORD_MIN_LENGTH_FLOOR, (int) $settings->get(SettingDefinitions::PASSWORD_MIN_LENGTH));

        $rule = Password::min($minimum)
            ->max(max(64, (int) config('security.password.max_length', 128)));

        if ($settings->get(SettingDefinitions::PASSWORD_CHECK_BREACHED)) {
            $rule->uncompromised();
        }

        return $rule;
    }
}
