<?php

namespace App\Notifications\Security;

class TwoFactorDisabledNotification extends SecurityNotification
{
    protected function subject(): string
    {
        return 'two-factor authentication turned off';
    }

    protected function summary(): array
    {
        return [
            'Two-factor authentication was turned off for your APES Newsroom account.',
            'Signing in now needs only your password or an emailed link.',
        ];
    }
}
