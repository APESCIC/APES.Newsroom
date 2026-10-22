<?php

namespace App\Notifications\Security;

class AccountUnlockedNotification extends SecurityNotification
{
    protected function subject(): string
    {
        return 'your account has been unlocked';
    }

    protected function summary(): array
    {
        return [
            'An administrator has unlocked your APES Newsroom account. You can sign in again.',
        ];
    }

    protected function notYouAdvice(): string
    {
        return 'Once signed in, check your account security page and change your password if you are unsure it is still private.';
    }
}
