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

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sending-domain-test',
            with: [
                'fromAddress' => $this->fromAddress,
                'mailgunDomain' => $this->mailgunDomain,
                'requestedBy' => $this->requestedBy,
                'sentAt' => now()->timezone('Europe/London')->format('j M Y, H:i'),
            ],
        );
    }
}
