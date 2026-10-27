<?php

namespace App\Enums;

enum ConsentAction: string
{
    case SignupRequested = 'signup_requested';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
    case PreferenceUpdated = 'preference_updated';
    case Suppressed = 'suppressed';
    case Unsuppressed = 'unsuppressed';
    case ContactUpdated = 'contact_updated';
}
