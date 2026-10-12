<?php

namespace App\Notifications\Security;

class PasskeyAddedNotification extends SecurityNotification
{
    public function __construct(public readonly string $passkeyName) {}

    protected function subject(): string
    {
        return 'passkey added';
    }

    protected function summary(): array
    {
        return [
            "A passkey named \"{$this->passkeyName}\" was added to your APES Newsroom account.",
            'It can now be used to sign in.',
        ];
    }
}
