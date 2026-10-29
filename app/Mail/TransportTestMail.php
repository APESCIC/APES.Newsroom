<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TransportTestMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $fromAddress,
        public readonly ?string $fromName,
        public readonly string $transportLabel,
        public readonly string $purposeLabel,
        public readonly string $requestedBy,
        public readonly ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress)] : [],
            subject: "[TEST] {$this->purposeLabel} through {$this->transportLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.transport-test',
            with: [
                'transportLabel' => $this->transportLabel,
                'purposeLabel' => $this->purposeLabel,
                'fromAddress' => $this->fromAddress,
                'requestedBy' => SendingDomainTestMail::plainName($this->requestedBy),
                'sentAt' => now()->timezone('Europe/London')->format('j M Y, H:i'),
            ],
        );
    }
}
