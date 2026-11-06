<?php

namespace App\Mail;

use App\Services\EmailTemplates\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SendingDomainTestMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $fromAddress,
        public readonly string $mailgunDomain,
        public readonly string $requestedBy,
        public readonly ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, 'APES Newsroom'),
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress)] : [],
            subject: $this->resolvedSubject(),
        );
    }

    /**
     * An admin's display name with Markdown syntax removed, so it cannot
     * turn into links or formatting in the email.
     */
    public static function plainName(string $name): string
    {
        return trim(str_replace(['[', ']', '(', ')', '*', '_', '`', '<', '>', '#', '!'], '', $name));
    }

    public function content(): Content
    {
        $placeholders = $this->placeholders();
        $templated = app(TemplatedMail::class);

        if ($templated->hasOverride('sending_domain_test')) {
            $rendered = $templated->renderOverride('sending_domain_test', $placeholders);

            return new Content(
                html: $rendered['html'],
                text: $rendered['text'],
            );
        }

        return new Content(
            markdown: 'mail.sending-domain-test',
            with: [
                'fromAddress' => $this->fromAddress,
                'mailgunDomain' => $this->mailgunDomain,
                'requestedBy' => self::plainName($this->requestedBy),
                'sentAt' => $placeholders['sent_at'],
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        return [
            'from_address' => $this->fromAddress,
            'mailgun_domain' => $this->mailgunDomain,
            'requested_by' => self::plainName($this->requestedBy),
            'sent_at' => now()->timezone('Europe/London')->format('j M Y, H:i'),
        ];
    }

    private function resolvedSubject(): string
    {
        $templated = app(TemplatedMail::class);

        if (! $templated->hasOverride('sending_domain_test')) {
            return '[TEST] Sending check for '.$this->fromAddress;
        }

        return $templated->renderOverride('sending_domain_test', $this->placeholders())['subject'];
    }
}
