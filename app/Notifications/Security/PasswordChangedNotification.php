<?php

namespace App\Notifications\Security;

class PasswordChangedNotification extends SecurityNotification
{
    public function __construct(private readonly bool $viaReset = false) {}

    protected function subject(): string
    {
        return 'your password was changed';
    }

    protected function summary(): array
    {
        return [
            $this->viaReset
                ? 'The password for your APES Newsroom account was reset using a reset link.'
                : 'The password for your APES Newsroom account was changed.',
            'Other signed-in sessions have been signed out.',
        ];
    }
}
