<?php

namespace App\Notifications\Security;

use App\Services\EmailTemplates\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells account holders about a security-relevant change. Content never
 * includes secrets, codes or sign-in links; each email asks "Was this you?"
 * and points to the account security page.
 */
abstract class SecurityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract protected function subject(): string;

    /**
     * @return list<string>
     */
    abstract protected function summary(): array;

    abstract protected function templateKey(): string;

    /**
     * @return array<string, string|int|float|null>
     */
    protected function templatePlaceholders(): array
    {
        return [];
    }

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
            key: $this->templateKey(),
            placeholders: $this->templatePlaceholders(),
            actionLabel: $this->actionText(),
            actionUrl: $this->actionUrl(),
            extraLines: [
                '**Was this you?** If so, you can ignore this email.',
                $this->notYouAdvice(),
            ],
            fallback: fn (): MailMessage => $this->defaultMail(),
        );
    }

    protected function defaultMail(): MailMessage
    {
        $message = (new MailMessage)->subject('APES Newsroom: '.$this->subject());

        foreach ($this->summary() as $line) {
            $message->line($line);
        }

        return $message
            ->line('**Was this you?** If so, you can ignore this email.')
            ->line($this->notYouAdvice())
            ->action($this->actionText(), $this->actionUrl());
    }

    protected function notYouAdvice(): string
    {
        return 'If it was not you, sign out of other sessions and change your password from your account security page, then contact the newsroom team.';
    }

    protected function actionText(): string
    {
        return 'Review account security';
    }

    protected function actionUrl(): string
    {
        return route('account.security');
    }
}
