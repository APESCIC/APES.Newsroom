import { router, usePage } from '@inertiajs/react';

type AuthUser = {
    id: number;
    name: string;
    email: string;
    role: string;
} | null;

const ROLES = [
    { key: 'guest', label: 'Guest' },
    { key: 'public', label: 'Public' },
    { key: 'staff', label: 'Staff' },
    { key: 'admin', label: 'Admin' },
] as const;

export default function RoleSwitcher() {
    const { auth, devTools } = usePage<{
        auth: { user: AuthUser };
        devTools?: boolean;
    }>().props;

    if (!devTools) {
        return null;
    }

    const current = auth.user?.role ?? 'guest';

    const switchTo = (key: (typeof ROLES)[number]['key']) => {
        if (key === 'guest') {
            router.post('/_dev/logout');
            return;
        }

        router.post(`/_dev/login/${key}`);
    };

    return (
        <div
            className="fixed inset-x-0 bottom-0 z-[9999] border-t border-amber-700/40 bg-amber-50/95 px-3 py-2 text-amber-950 shadow-[0_-4px_16px_rgba(0,0,0,0.08)] backdrop-blur-sm"
            role="region"
            aria-label="Local role preview"
        >
            <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-2">
                <p className="text-xs font-semibold uppercase tracking-wide text-amber-800">
                    Local role preview
                    <span className="ml-2 font-normal normal-case tracking-normal text-amber-700">
                        {auth.user ? `${auth.user.name} (${auth.user.role})` : 'Guest'}
                    </span>
                </p>
                <div className="flex flex-wrap gap-1.5">
                    {ROLES.map((role) => {
                        const active = current === role.key;

                        return (
                            <button
                                key={role.key}
                                type="button"
                                onClick={() => switchTo(role.key)}
                                disabled={active}
                                className={
                                    active
                                        ? 'rounded bg-amber-800 px-2.5 py-1 text-xs font-medium text-white'
                                        : 'rounded border border-amber-700/30 bg-white px-2.5 py-1 text-xs font-medium text-amber-950 hover:bg-amber-100'
                                }
                            >
                                {role.label}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
