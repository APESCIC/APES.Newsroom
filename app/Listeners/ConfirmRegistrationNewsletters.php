<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Mailing\ConsentService;
use App\Services\Mailing\LinkMailingContactService;
use Illuminate\Auth\Events\Verified;

/**
 * After verification: link any mailing contact by email, then confirm
 * registration newsletter requests. Linking first so confirmations run on
 * the attached contact; opt-outs are preserved in ConsentService.
 */
class ConfirmRegistrationNewsletters
{
    public function __construct(
        private readonly LinkMailingContactService $links,
        private readonly ConsentService $consent,
    ) {}

    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->links->link($event->user);
        $this->consent->confirmRegistrationRequests($event->user);
    }
}
