<?php

namespace App\Notifications\Security;

class ApiTokenCreatedNotification extends SecurityNotification
{
    public function __construct(public readonly string $tokenName) {}

    protected function subject(): string
    {
        return 'a new Admin API token was created';
    }

    protected function summary(): array
    {
        return [
            "An Admin API token named \"{$this->tokenName}\" was created for your account.",
            'Tokens act with your permissions. The token itself is never sent by email.',
        ];
    }

    protected function notYouAdvice(): string
    {
        return 'If you did not expect this token, ask a newsroom administrator to revoke it straight away.';
    }
}
