<?php

namespace App\Notifications\Security;

class RecoveryCodesRegeneratedNotification extends SecurityNotification
{
    protected function subject(): string
    {
        return 'new recovery codes generated';
    }

    protected function summary(): array
    {
        return [
            'New two-factor recovery codes were generated for your APES Newsroom account.',
            'Your previous recovery codes no longer work. The codes are never sent by email.',
        ];
    }
}
