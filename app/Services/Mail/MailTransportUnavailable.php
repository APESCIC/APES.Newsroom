<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The transport itself cannot send right now (not configured, sender domain
 * not approved, credentials refused, provider outage), as opposed to a
 * refusal of one message. Permanent problems need an admin; the rest may
 * clear on retry.
 */
class MailTransportUnavailable extends TransportException
{
    public function __construct(string $message, public readonly bool $permanent = true)
    {
        parent::__construct($message);
    }
}
