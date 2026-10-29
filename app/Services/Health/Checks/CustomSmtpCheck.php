<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mail\MailTransports;
use App\Services\Mail\SmtpHandshake;
use App\Support\Integrations\IntegrationDefinitions;

/**
 * Signs in to the custom SMTP server without sending a message.
 */
class CustomSmtpCheck extends ManagedKeyCheck
{
    public function __construct(IntegrationSecrets $secrets, private readonly SmtpHandshake $handshake)
    {
        parent::__construct($secrets);
    }

    public function provider(): string
    {
        return IntegrationDefinitions::CUSTOM_SMTP;
    }

    protected function requiredKeys(): array
    {
        return ['host', 'password'];
    }

    public function run(array $overrides = []): CheckResult
    {
        $host = (string) $this->value('host', $overrides);
        $error = $this->handshake->check([
            'host' => $host,
            'port' => $this->value('port', $overrides),
            'scheme' => $this->value('scheme', $overrides),
            'username' => $this->value('username', $overrides),
            'password' => $this->value('password', $overrides),
        ]);

        if ($error !== null) {
            return CheckResult::fail("The SMTP server refused the connection or sign-in: {$error}");
        }

        return in_array(strtolower($host), MailTransports::TRANSACTIONAL_ONLY_HOSTS, true)
            ? CheckResult::warn('Signed in. ZeptoMail is for transactional mail only, so use it for system mail, not newsletters.')
            : CheckResult::pass('Signed in to the SMTP server.');
    }
}
