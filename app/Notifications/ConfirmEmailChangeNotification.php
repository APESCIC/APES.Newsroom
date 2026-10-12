<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the requested new address; the change only applies once this
 * link is followed.
 */
class ConfirmEmailChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        private readonly int $minutes,
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
            ->subject('Confirm your new APES Newsroom email address')
            ->line('Confirm this address to finish changing the email on your APES Newsroom account.')
            ->action('Confirm new email address', $this->url)
            ->line("The link expires in {$this->minutes} minutes.")
            ->line('If you did not ask for this, ignore this email; nothing will change.');
    }
}
