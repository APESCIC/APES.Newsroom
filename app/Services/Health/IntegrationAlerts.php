<?php

namespace App\Services\Health;

use App\Enums\IntegrationCheckStatus;
use App\Enums\Role;
use App\Models\IntegrationCheckResult;
use App\Models\User;
use App\Notifications\IntegrationCheckFailedNotification;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Support\Facades\Notification;

/**
 * Emails admins when a provider's check moves into failing. A provider that
 * keeps failing does not alert again until it has passed or warned first.
 */
class IntegrationAlerts
{
    public function afterRun(?IntegrationCheckResult $previous, IntegrationCheckResult $current): bool
    {
        if ($current->trigger === IntegrationCheckResult::TRIGGER_PRE_SAVE || $current->status !== IntegrationCheckStatus::Fail) {
            return false;
        }

        if ($previous?->status === IntegrationCheckStatus::Fail) {
            return false;
        }

        $recipients = User::query()
            ->whereIn('role', [Role::Admin->value, Role::SuperAdmin->value])
            ->whereNull('locked_at')
            ->get();

        if ($recipients->isEmpty()) {
            return false;
        }

        Notification::send($recipients, new IntegrationCheckFailedNotification(
            IntegrationDefinitions::provider($current->provider)['label'],
            $current->message,
            $current->checked_at->timezone('Europe/London')->format('j M Y H:i'),
        ));

        return true;
    }
}
