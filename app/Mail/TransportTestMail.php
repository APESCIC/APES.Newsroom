<?php

namespace App\Mail;

use App\Services\EmailTemplates\TemplatedMail;
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
            subject: $this->resolvedSubject(),
        );
    }

    public function content(): Content
    {
        $placeholders = $this->placeholders();
        $templated = app(TemplatedMail::class);

        if ($templated->hasOverride('transport_test')) {
            $rendered = $templated->renderOverride('transport_test', $placeholders);

            return new Content(
                html: $rendered['html'],
                text: $rendered['text'],
            );
        }

        return new Content(
            markdown: 'mail.transport-test',
            with: [
                'transportLabel' => $this->transportLabel,
                'purposeLabel' => $this->purposeLabel,
                'fromAddress' => $this->fromAddress,
                'requestedBy' => SendingDomainTestMail::plainName($this->requestedBy),
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
            'purpose_label' => $this->purposeLabel,
            'from_address' => $this->fromAddress,
            'transport_label' => $this->transportLabel,
            'requested_by' => SendingDomainTestMail::plainName($this->requestedBy),
            'sent_at' => now()->timezone('Europe/London')->format('j M Y, H:i'),
        ];
    }

    private function resolvedSubject(): string
    {
        $templated = app(TemplatedMail::class);

        if (! $templated->hasOverride('transport_test')) {
            return "[TEST] {$this->purposeLabel} through {$this->transportLabel}";
        }

        return $templated->renderOverride('transport_test', $this->placeholders())['subject'];
    }
}
