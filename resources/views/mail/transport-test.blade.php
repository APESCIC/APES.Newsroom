<x-mail::message>
**TEST EMAIL** — sent from Admin → Settings → Mail sending.

This message was sent as **{{ $purposeLabel }}** from **{{ $fromAddress }}** through **{{ $transportLabel }}** at {{ $sentAt }}, at the request of {{ $requestedBy }}.

If it reached your inbox, this transport can send {{ strtolower($purposeLabel) }}. To confirm SPF, DKIM and DMARC alignment, open the message headers and look for `dmarc=pass` in `Authentication-Results`.

No action is needed. You can delete this email.
</x-mail::message>
