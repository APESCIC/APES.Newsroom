<?php

namespace App\Notifications;

use App\Models\Newsletter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmNewsletterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Newsletter $newsletter,
        private readonly string $confirmUrl,
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
        $purpose = $this->newsletter->description;

        $message = (new MailMessage)
            ->subject('Confirm your '.$this->newsletter->name.' subscription')
            ->line('You asked to join the '.$this->newsletter->name.' newsletter.')
            ->action('Confirm subscription', $this->confirmUrl)
            ->line('If you did not request this, you can ignore this email.');

        if (is_string($purpose) && $purpose !== '') {
            $message->line($purpose);
        }

        return $message;
    }
}
