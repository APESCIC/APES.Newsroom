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
    case OpenTrackingConsented = 'open_tracking_consented';
    case OpenTrackingWithdrawn = 'open_tracking_withdrawn';
    case ClickTrackingOptedOut = 'click_tracking_opted_out';
    case ClickTrackingOptedIn = 'click_tracking_opted_in';
}
