<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;

/**
 * Whether the admin security policy requires this user to enrol a second
 * factor. Cloudron SSO accounts are exempt: Cloudron enforces its own MFA.
 */
class TwoFactorRequirement
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function appliesTo(User $user): bool
    {
        if ($user->requiresSso()) {
            return false;
        }

        $roles = (array) $this->settings->get(SettingDefinitions::TWO_FACTOR_REQUIRED_ROLES);

        return in_array($user->role->value, $roles, true);
    }

    public function isUnmet(User $user): bool
    {
        return $this->appliesTo($user)
            && ! $user->hasTwoFactorEnabled()
            && ! $user->passkeys()->exists();
    }
}
