<?php

namespace App\Notifications;

use App\Services\EmailTemplates\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(TemplatedMail::class)->build(
            key: 'magic_link',
            placeholders: ['url' => $this->url],
            actionLabel: 'Sign in',
            actionUrl: $this->url,
            fallback: fn (): MailMessage => (new MailMessage)
                ->subject('Your APES Newsroom sign-in link')
                ->line('Click the button below to sign in. This link expires in 15 minutes and can only be used once.')
                ->action('Sign in', $this->url)
                ->line('If you did not request this, you can safely ignore this email.'),
        );
    }
}
