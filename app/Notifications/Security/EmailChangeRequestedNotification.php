<?php

namespace App\Notifications\Security;

/**
 * Sent to the current address when someone asks to move the account to a
 * new one; the cancel link works until the change is confirmed.
 */
class EmailChangeRequestedNotification extends SecurityNotification
{
    public function __construct(
        public readonly string $maskedNewEmail,
        private readonly string $cancelUrl,
    ) {}

    protected function subject(): string
    {
        return 'email address change requested';
    }

    protected function summary(): array
    {
        return [
            "Someone asked to change the email address on your APES Newsroom account to {$this->maskedNewEmail}.",
            'Your email address stays the same until the new address is confirmed.',
        ];
    }

    protected function notYouAdvice(): string
    {
        return 'If it was not you, cancel the change below, then change your password and sign out of other sessions.';
    }

    protected function actionText(): string
    {
        return 'Cancel this change';
    }

    protected function actionUrl(): string
    {
        return $this->cancelUrl;
    }
}
