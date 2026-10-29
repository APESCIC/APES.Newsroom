<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Connects, negotiates TLS and signs in to an SMTP server without sending
 * anything. The server's reply text is returned on failure; it never
 * contains the password.
 */
class SmtpHandshake
{
    public const TIMEOUT_SECONDS = 8;

    /**
     * @param  array{host?: string|null, port?: int|string|null, scheme?: string|null, username?: string|null, password?: string|null}  $config
     * @return string|null The error, or null when the server accepted the sign-in.
     */
    public function check(array $config): ?string
    {
        $host = (string) ($config['host'] ?? '');
        if ($host === '') {
            return 'No SMTP host is set.';
        }

        $scheme = ($config['scheme'] ?? 'smtp') === 'smtps' ? 'smtps' : 'smtp';
        $port = (int) ($config['port'] ?? 0) ?: ($scheme === 'smtps' ? 465 : 587);

        $transport = $this->transport($host, $port, $scheme === 'smtps');
        if (filled($config['username'] ?? null)) {
            $transport->setUsername((string) $config['username']);
            $transport->setPassword((string) ($config['password'] ?? ''));
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
        }

        try {
            $transport->start();
            $transport->stop();
        } catch (TransportExceptionInterface $exception) {
            return mb_substr(trim($exception->getMessage()), 0, 500);
        }

        return null;
    }

    protected function transport(string $host, int $port, bool $tls): EsmtpTransport
    {
        return new EsmtpTransport($host, $port, $tls);
    }
}
