<x-mail::message>
**TEST EMAIL** — sent from Admin → Settings → Email sending domains.

This message was sent from **{{ $fromAddress }}** through the Mailgun domain **{{ $mailgunDomain }}** at {{ $sentAt }}, at the request of {{ $requestedBy }}.

If it reached your inbox, the APES Newsroom can send from this address. To confirm SPF, DKIM and DMARC alignment, open the message headers and look for `dmarc=pass` in `Authentication-Results`.

No action is needed. You can delete this email.
</x-mail::message>
