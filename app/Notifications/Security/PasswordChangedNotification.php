<?php

namespace App\Notifications\Security;

use App\Services\EmailTemplates\TemplatedMail;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordChangedNotification extends SecurityNotification
{
    public function __construct(private readonly bool $viaReset = false) {}

    protected function subject(): string
    {
        return 'your password was changed';
    }

    protected function summary(): array
    {
        return [
            $this->viaReset
                ? 'The password for your APES Newsroom account was reset using a reset link.'
                : 'The password for your APES Newsroom account was changed.',
            'Other signed-in sessions have been signed out.',
        ];
    }

    protected function templateKey(): string
    {
        return 'password_changed';
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
            viaReset: $this->viaReset,
            fallback: fn (): MailMessage => $this->defaultMail(),
        );
    }
}
