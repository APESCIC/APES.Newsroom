<?php

namespace App\Services\Mailing;

use App\Models\MailingContact;

final class LinkMailingContactResult
{
    public function __construct(
        public readonly ?MailingContact $contact,
        public readonly string $outcome,
        public readonly bool $wasPreExisting = false,
        public readonly bool $hadSubscriptions = false,
        public readonly ?string $email = null,
    ) {}

    public static function created(MailingContact $contact): self
    {
        return new self($contact, 'created');
    }

    public static function linked(MailingContact $contact, bool $wasPreExisting, bool $hadSubscriptions): self
    {
        return new self($contact, 'linked', $wasPreExisting, $hadSubscriptions);
    }

    public static function alreadyLinked(MailingContact $contact, bool $wasPreExisting, bool $hadSubscriptions): self
    {
        return new self($contact, 'already_linked', $wasPreExisting, $hadSubscriptions);
    }

    public static function skipped(string $email): self
    {
        return new self(null, 'skipped', email: $email);
    }

    public function linkedPreExistingWithSubscriptions(): bool
    {
        return $this->outcome === 'linked'
            && $this->wasPreExisting
            && $this->hadSubscriptions;
    }
}
