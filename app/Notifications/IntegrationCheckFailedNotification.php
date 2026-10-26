<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to admins once when an integration check moves into failing. The
 * message is the check's own plain text, which never contains key material.
 */
class IntegrationCheckFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $providerLabel,
        public readonly string $message,
        public readonly string $checkedAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("APES Newsroom: {$this->providerLabel} integration check is failing")
            ->line("The {$this->providerLabel} integration check started failing at {$this->checkedAt}.")
            ->line($this->message)
            ->line('Features that depend on it may not work until it is fixed. You will not get another email while it keeps failing.')
            ->action('Review integrations', route('admin.settings.integrations.index'));
    }
}
