<?php

namespace App\Notifications\Security;

class NewDeviceSignInNotification extends SecurityNotification
{
    public function __construct(
        public readonly string $device,
        private readonly ?string $maskedIp,
        private readonly string $signedInAt,
    ) {}

    protected function subject(): string
    {
        return 'new sign-in to your account';
    }

    protected function summary(): array
    {
        return array_values(array_filter([
            'Your APES Newsroom account was signed in to from a device we have not seen recently.',
            "Device: {$this->device}",
            $this->maskedIp ? "Network: {$this->maskedIp}" : null,
            "Time: {$this->signedInAt}",
        ]));
    }

    protected function templateKey(): string
    {
        return 'new_device_sign_in';
    }

    protected function templatePlaceholders(): array
    {
        return [
            'device' => $this->device,
            'masked_ip' => $this->maskedIp ?? '',
            'signed_in_at' => $this->signedInAt,
        ];
    }
}
