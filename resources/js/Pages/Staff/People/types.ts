import type { ContactDetailsValues } from '../../../Components/Account/ContactDetailsFields';
import type { Role, SignInMethod } from '../../Admin/Users/types';

export type SubscriptionRow = {
    subscription_id: number | null;
    newsletter_id: number | null;
    label: string;
    status: 'pending' | 'confirmed' | 'unsubscribed' | null;
    confirmed_at: string | null;
    unsubscribed_at: string | null;
    can_unsubscribe: boolean;
    can_subscribe: boolean;
};

export type ConsentEventRow = {
    id: number;
    action: string;
    source: string;
    created_at: string | null;
};

export type DeliveryStatus = {
    reason: string;
    label: string;
    source: string | null;
    since: string | null;
    last_event: { type: 'bounce' | 'temporary_failure' | 'complained' | 'unsubscribed'; at: string | null; reason: string | null } | null;
};

/** One line describing why an address is blocked, for staff views. */
export function deliveryStatusText(status: DeliveryStatus): string {
    const since = status.since ? ` since ${new Date(status.since).toLocaleDateString('en-GB', { dateStyle: 'medium' })}` : '';
    const event = status.last_event;
    const detail = event?.reason ? `. Last problem: ${event.reason}` : '';
    return `${status.label}${since}${detail}`;
}

export type Mailing = {
    contact_id: number;
    email: string;
    linked: boolean;
    suppressed: boolean;
    delivery_status?: DeliveryStatus | null;
    subscriptions: SubscriptionRow[];
    consent_history: ConsentEventRow[];
};

export type MembershipInfo = {
    status: string;
    status_label: string;
    access_label: string;
    is_paying: boolean;
    has_paid_access: boolean;
    plan_name: string | null;
    interval: string | null;
    current_period_end: string | null;
    stripe_linked: boolean;
    comp: { comped_at: string | null; by: string | null; reason: string | null } | null;
    note: { text: string | null; updated_at: string | null; updated_by: string | null } | null;
};

export type ProfileInfo = {
    id: number;
    display_name: string | null;
    bio: string | null;
    avatar_url: string | null;
    moderation_status: string;
    public_opt_in: boolean;
};

export type UserPerson = {
    kind: 'user';
    user: {
        id: number;
        name: string;
        email: string;
        role: Role;
        sign_in_method: SignInMethod;
        sso_managed: boolean;
        email_verified: boolean;
        locked: boolean;
        created_at: string | null;
        pending_email: { email: string; expires_at: string | null } | null;
    };
    contact_details: ContactDetailsValues | null;
    membership: MembershipInfo;
    mailing: Mailing | null;
    profile: ProfileInfo | null;
    can: {
        update: boolean;
        view_admin_user: boolean;
        manage_comp: boolean;
        edit_note: boolean;
        manage_subscriptions: boolean;
        suppress: boolean;
        update_profile: boolean;
    };
};

export type ContactPerson = {
    kind: 'contact';
    contact: { id: number; name: string | null; email: string; created_at: string | null };
    mailing: Mailing;
    can: { update: boolean; delete: boolean; manage_subscriptions: boolean; suppress: boolean };
};

export type Person = UserPerson | ContactPerson;

const CONSENT_LABELS: Record<string, string> = {
    signup_requested: 'Confirmation requested',
    confirmed: 'Confirmed',
    unsubscribed: 'Unsubscribed',
    preference_updated: 'Preferences updated',
    suppressed: 'Suppressed',
    unsuppressed: 'Suppression removed',
    contact_updated: 'Contact details changed',
    open_tracking_consented: 'Allowed open tracking',
    open_tracking_withdrawn: 'Stopped open tracking',
    click_tracking_opted_out: 'Turned off click tracking',
    click_tracking_opted_in: 'Allowed click tracking',
};

export function consentLabel(action: string): string {
    return CONSENT_LABELS[action] ?? action;
}

export function subscriptionLabel(status: SubscriptionRow['status']): string {
    switch (status) {
        case 'confirmed':
            return 'Subscribed';
        case 'pending':
            return 'Awaiting confirmation';
        case 'unsubscribed':
            return 'Unsubscribed';
        default:
            return 'Not subscribed';
    }
}
