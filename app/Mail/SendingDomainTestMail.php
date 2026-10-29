<?php

namespace App\Mail;

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
            subject: '[TEST] Sending check for '.$this->fromAddress,
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
        return new Content(
            markdown: 'mail.sending-domain-test',
            with: [
                'fromAddress' => $this->fromAddress,
                'mailgunDomain' => $this->mailgunDomain,
                'requestedBy' => self::plainName($this->requestedBy),
                'sentAt' => now()->timezone('Europe/London')->format('j M Y, H:i'),
            ],
        );
    }
}
