import { router, usePage } from '@inertiajs/react';

type AuthUser = {
    id: number;
    name: string;
    email: string;
    role: string;
} | null;

type DevLoginProps = {
    enabled: boolean;
    profiles: Array<{ key: string; label: string; email: string }>;
    current: string | null;
    needsConfirm: boolean;
} | null;

export default function RoleSwitcher() {
    const { auth, devTools, devLogin, flash } = usePage<{
        auth: { user: AuthUser };
        devTools?: boolean;
        devLogin?: DevLoginProps;
        flash?: { status?: string | null };
    }>().props;

    if (!devTools || !devLogin?.enabled) {
        return null;
    }

    const current = devLogin.current ?? (auth.user ? null : 'guest');
    const returnTo = typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/';

    const switchTo = (key: string) => {
        if (key === 'guest') {
            router.post('/_dev/logout');
            return;
        }

        router.post('/_dev/login', {
            profile: key,
            return_to: returnTo,
        });
    };

    return (
        <div
            className="fixed inset-x-0 bottom-0 z-[9999] border-t border-amber-700/40 bg-amber-50/95 px-3 py-2 text-amber-950 shadow-[0_-4px_16px_rgba(0,0,0,0.08)] backdrop-blur-sm"
            role="region"
            aria-label="Local developer role preview"
            data-dev-pill
        >
            <div className="mx-auto flex max-w-5xl flex-col gap-2">
                {devLogin.needsConfirm && (
                    <p className="text-xs font-medium text-amber-900" data-dev-confirm>
                        Confirm your developer role to continue with a sensitive action.
                    </p>
                )}
                {flash?.status && (
                    <p className="text-xs text-amber-800">{flash.status}</p>
                )}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs font-semibold tracking-wide text-amber-800 uppercase">
                        Developer sign-in
                        <span className="ml-2 font-normal normal-case tracking-normal text-amber-700">
                            {auth.user
                                ? `${auth.user.name} (${devLogin.current ?? auth.user.role})`
                                : 'Guest'}
                            <span className="ml-1 rounded bg-amber-200/80 px-1 py-0.5 text-[10px] font-semibold uppercase">
                                Local only
                            </span>
                        </span>
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={() => switchTo('guest')}
                            disabled={!auth.user}
                            className={
                                !auth.user
                                    ? 'rounded bg-amber-800 px-2.5 py-1 text-xs font-medium text-white'
                                    : 'rounded border border-amber-700/30 bg-white px-2.5 py-1 text-xs font-medium text-amber-950 hover:bg-amber-100'
                            }
                        >
                            Sign out
                        </button>
                        {devLogin.profiles.map((role) => {
                            const active = current === role.key;

                            return (
                                <button
                                    key={role.key}
                                    type="button"
                                    onClick={() => switchTo(role.key)}
                                    disabled={active && !devLogin.needsConfirm}
                                    aria-current={active ? 'true' : undefined}
                                    className={
                                        active
                                            ? 'rounded bg-amber-800 px-2.5 py-1 text-xs font-medium text-white'
                                            : 'rounded border border-amber-700/30 bg-white px-2.5 py-1 text-xs font-medium text-amber-950 hover:bg-amber-100'
                                    }
                                >
                                    {devLogin.needsConfirm && active ? `Confirm ${role.label}` : role.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
}
