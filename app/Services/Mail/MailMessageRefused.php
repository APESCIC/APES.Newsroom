<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The provider refused this one message (for example an invalid recipient).
 * Retrying it will not help, and other messages can still be sent.
 */
class MailMessageRefused extends TransportException {}
