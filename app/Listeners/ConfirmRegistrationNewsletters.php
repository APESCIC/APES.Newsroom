<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Mailing\ConsentService;
use Illuminate\Auth\Events\Verified;

class ConfirmRegistrationNewsletters
{
    public function __construct(private readonly ConsentService $consent) {}

    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->consent->confirmRegistrationRequests($event->user);
        }
    }
}
