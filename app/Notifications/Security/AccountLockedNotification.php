<?php

namespace App\Notifications\Security;

use Illuminate\Notifications\Messages\MailMessage;

class AccountLockedNotification extends SecurityNotification
{
    protected function subject(): string
    {
        return 'your account has been locked';
    }

    protected function summary(): array
    {
        return [
            'An administrator has locked your APES Newsroom account, usually to protect it after suspicious activity.',
            'You have been signed out everywhere and cannot sign in until the account is unlocked.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('APES Newsroom: '.$this->subject());

        foreach ($this->summary() as $line) {
            $message->line($line);
        }

        return $message->line('If you were not expecting this, please contact the newsroom team.');
    }
}
