import type { ContactDetailsValues } from '../../../Components/Account/ContactDetailsFields';

export type Role = 'public' | 'staff' | 'admin' | 'super_admin';

export type SignInMethod = 'sso' | 'password' | 'passwordless';

export type UserRow = {
    id: number;
    name: string;
    email: string;
    role: Role;
    sign_in_method: SignInMethod;
    email_verified: boolean;
    two_factor_enabled: boolean;
    passkeys_count: number;
    locked: boolean;
    created_at: string | null;
};

export type SecurityEvent = {
    id: number;
    action: string;
    created_at: string | null;
    actor: { id: number; name: string; email: string } | null;
    ip_address: string | null;
    payload: { key: string; value: string }[];
};

export type EraseMode = 'deleted' | 'anonymised';

export type PendingEmail = { email: string; expires_at: string | null };

export type EditableUser = {
    id: number;
    name: string;
    email: string;
    sso_managed: boolean;
    pending_email: PendingEmail | null;
    contact_details: ContactDetailsValues;
};

export type UserDetail = UserRow & {
    lock: { locked_at: string | null; reason: string | null; by: { id: number; name: string } | null } | null;
    can: { manage_security: boolean; change_role: boolean; update: boolean; resend_verification: boolean; erase: boolean };
    erase_mode: EraseMode | null;
    contact_details: ContactDetailsValues;
    pending_email: PendingEmail | null;
    email_verified_at: string | null;
    sso_managed: boolean;
    role_source: { type: 'directory' | 'in_app'; groups: string[] };
    recovery_codes_remaining: number;
    passkeys: { id: number; name: string; created_at: string | null; last_used_at: string | null }[];
    sessions: {
        id: number;
        browser: string | null;
        platform: string | null;
        device_type: string | null;
        ip_address: string | null;
        remembered: boolean;
        last_seen_at: string | null;
    }[];
    api_tokens: { id: number; name: string; status?: 'active' | 'expired' | 'revoked'; created_at: string | null; last_used_at: string | null }[];
    security_events: SecurityEvent[];
    login_history: LoginEvent[];
};

export type LoginEvent = {
    id: number;
    action: string;
    result: 'success' | 'failure' | 'info';
    method: string | null;
    created_at: string | null;
    ip_address: string | null;
    browser: string | null;
    platform: string | null;
    device_type: string | null;
};

export function resultLabel(result: LoginEvent['result']): string {
    switch (result) {
        case 'success':
            return 'Succeeded';
        case 'failure':
            return 'Failed';
        case 'info':
            return 'Event';
        default: {
            const unreachable: never = result;
            return unreachable;
        }
    }
}

export function roleLabel(role: Role): string {
    switch (role) {
        case 'public':
            return 'Public';
        case 'staff':
            return 'Staff';
        case 'admin':
            return 'Admin';
        case 'super_admin':
            return 'Super admin';
        default: {
            const unreachable: never = role;
            return unreachable;
        }
    }
}

export function methodLabel(method: SignInMethod): string {
    switch (method) {
        case 'sso':
            return 'Cloudron SSO';
        case 'password':
            return 'Password';
        case 'passwordless':
            return 'Magic link only';
        default: {
            const unreachable: never = method;
            return unreachable;
        }
    }
}

const EVENT_LABELS: Record<string, string> = {
    'user.updated': 'Details edited by an admin',
    'user.verification_resent': 'Verification email resent',
    'user.erased': 'Account erased',
    'user.locked': 'Locked (suspended)',
    'user.unlocked': 'Unlocked',
    'user.signed_out': 'Signed out everywhere',
    'user.password_reset_forced': 'Password reset forced',
    'user.two_factor_reset': 'Two-factor turned off',
    'user.passkeys_removed': 'Passkeys removed',
    'user.role_changed': 'Role changed',
    'account.contact_details_updated': 'Contact details updated by the user',
};

export function eventLabel(action: string): string {
    return EVENT_LABELS[action] ?? action;
}

export function formatWhen(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/London' }).format(new Date(iso));
}
