<?php

namespace App\Notifications\Security;

class PasskeyRemovedNotification extends SecurityNotification
{
    public function __construct(public readonly string $passkeyName) {}

    protected function subject(): string
    {
        return 'passkey removed';
    }

    protected function summary(): array
    {
        return [
            "The passkey named \"{$this->passkeyName}\" was removed from your APES Newsroom account.",
            'It can no longer be used to sign in.',
        ];
    }
}
