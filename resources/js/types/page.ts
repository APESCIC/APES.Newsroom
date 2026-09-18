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
    };
};

export type SharedPageProps = {
    appName: string;
    auth: SharedAuth;
    devTools?: boolean;
    flash?: { status?: string | null };
    errors?: Record<string, string>;
};
