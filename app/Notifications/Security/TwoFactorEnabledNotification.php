<?php

namespace App\Notifications\Security;

class TwoFactorEnabledNotification extends SecurityNotification
{
    protected function subject(): string
    {
        return 'two-factor authentication turned on';
    }

    protected function summary(): array
    {
        return [
            'Two-factor authentication was turned on for your APES Newsroom account.',
            'You will be asked for a code from your authenticator app when you sign in.',
        ];
    }
}
