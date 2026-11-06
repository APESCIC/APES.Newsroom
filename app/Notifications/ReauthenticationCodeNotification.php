<?php

namespace App\Notifications;

use App\Services\EmailTemplates\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReauthenticationCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
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
        return app(TemplatedMail::class)->build(
            key: 'reauthentication_code',
            placeholders: [
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
            fallback: fn (): MailMessage => (new MailMessage)
                ->subject('Your APES Newsroom confirmation code')
                ->line('Use this code to confirm it is you before changing your account security settings:')
                ->line("**{$this->code}**")
                ->line("The code expires in {$this->minutes} minutes. Never share it with anyone.")
                ->line('If you did not ask for this code, someone may be using your account. Sign out of other sessions and contact the newsroom team.'),
        );
    }
}
