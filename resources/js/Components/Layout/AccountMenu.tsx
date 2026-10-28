import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import type { SharedPageProps } from '../../types/page';

type AccountMenuBreakpoint = 'all' | 'desktop' | 'mobile';

export default function AccountMenu({
    breakpoint = 'all',
    onHiddenWhileFocused,
}: {
    breakpoint?: AccountMenuBreakpoint;
    onHiddenWhileFocused?: () => void;
}) {
    const { auth } = usePage<SharedPageProps>().props;
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const rootRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (breakpoint === 'all' || typeof window.matchMedia !== 'function') return;

        const desktopQuery = window.matchMedia('(min-width: 64rem)');
        const closeWhenHidden = ({ matches }: Pick<MediaQueryListEvent, 'matches'>) => {
            const visible = breakpoint === 'desktop' ? matches : !matches;
            if (!visible) {
                const presentationHadFocus = rootRef.current?.contains(document.activeElement) ?? false;
                setOpen(false);
                if (presentationHadFocus) onHiddenWhileFocused?.();
            }
        };

        closeWhenHidden(desktopQuery);
        desktopQuery.addEventListener('change', closeWhenHidden);
        return () => desktopQuery.removeEventListener('change', closeWhenHidden);
    }, [breakpoint, onHiddenWhileFocused]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopImmediatePropagation();
                setOpen(false);
                triggerRef.current?.focus();
            }
        };
        const onClick = (e: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('mousedown', onClick);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('mousedown', onClick);
        };
    }, [open]);

    if (!auth.user) {
        return (
            <div className="flex items-center gap-2 text-sm">
                <Link href="/login" className="public-nav-link">
                    Login
                </Link>
                <Link href="/register" className="sg-btn sg-btn-primary">
                    Register
                </Link>
            </div>
        );
    }

    return (
        <div className="relative" ref={rootRef}>
            <button
                ref={triggerRef}
                type="button"
                className="sg-btn sg-btn-secondary"
                aria-expanded={open}
                aria-controls={menuId}
                onClick={() => setOpen((v) => !v)}
            >
                Account
            </button>
            {open && (
                <div id={menuId} role="menu" className="sg-menu mt-2 min-w-44">
                    <Link href="/account" role="menuitem" className="sg-menu-item min-h-11 text-sm" onClick={() => setOpen(false)}>
                        Profile
                    </Link>
                    {auth.can.accessAdmin && (
                        <Link
                            href="/admin/moderation"
                            role="menuitem"
                            className="sg-menu-item min-h-11 text-sm font-semibold"
                            onClick={() => setOpen(false)}
                        >
                            Admin panel
                        </Link>
                    )}
                    {auth.can.accessStaff && (
                        <Link
                            href="/staff/posts"
                            role="menuitem"
                            className="sg-menu-item min-h-11 text-sm"
                            onClick={() => setOpen(false)}
                        >
                            Staff desk
                        </Link>
                    )}
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        role="menuitem"
                        className="sg-menu-item min-h-11 text-sm"
                        onClick={() => setOpen(false)}
                    >
                        Sign out
                    </Link>
                </div>
            )}
        </div>
    );
}
