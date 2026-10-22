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

export type UserDetail = UserRow & {
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
    api_tokens: { id: number; name: string; created_at: string | null; last_used_at: string | null }[];
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

export function formatWhen(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/London' }).format(new Date(iso));
}
