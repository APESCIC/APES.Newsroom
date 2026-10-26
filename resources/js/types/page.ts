import type { EditorDriver } from './editor';

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    role: 'public' | 'staff' | 'admin' | 'super_admin';
};

export type SharedAuth = {
    user: AuthUser | null;
    can: {
        accessStaff: boolean;
        accessAdmin: boolean;
        accessSuperAdmin?: boolean;
    };
};

export type PublicChannel = {
    value: string;
    slug: string;
    label: string;
    description: string | null;
    signup_slug: string;
};

export type SharedPageProps = {
    appName: string;
    auth: SharedAuth;
    publicChannels?: PublicChannel[];
    currentRelease?: {
        version: string;
        slug: string;
    } | null;
    devTools?: boolean;
    editorDriver?: EditorDriver;
    flash?: { status?: string | null };
    errors?: Record<string, string>;
};
