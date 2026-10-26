import { Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { channelMeta } from '../../channelMeta';
import type { PublicChannel, SharedPageProps } from '../../types/page';
import ApesLogo from '../Brand/ApesLogo';
import LineIcon from '../Icons/LineIcon';
import AccountMenu from './AccountMenu';
import SiteFooter from './SiteFooter';

function isCurrentPath(currentPath: string, href: string): boolean {
    return href === '/' ? currentPath === '/' : currentPath === href || currentPath.startsWith(`${href}/`);
}

function ChannelsMenu({ channels, currentPath }: { channels: PublicChannel[]; currentPath: string }) {
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const rootRef = useRef<HTMLLIElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const containsCurrent = channels.some((channel) => isCurrentPath(currentPath, `/${channel.slug}`));

    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;

        const desktopQuery = window.matchMedia('(min-width: 64rem)');
        const closeWhenHidden = ({ matches }: Pick<MediaQueryListEvent, 'matches'>) => {
            if (!matches) setOpen(false);
        };

        desktopQuery.addEventListener('change', closeWhenHidden);
        return () => desktopQuery.removeEventListener('change', closeWhenHidden);
    }, []);

    useEffect(() => {
        if (!open) return;

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
                setOpen(false);
                triggerRef.current?.focus();
            }
        };
        const onPointer = (event: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onFocusOut = (event: FocusEvent) => {
            if (rootRef.current && event.relatedTarget instanceof Node && !rootRef.current.contains(event.relatedTarget)) {
                setOpen(false);
            }
        };
        const root = rootRef.current;

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('mousedown', onPointer);
        root?.addEventListener('focusout', onFocusOut);
        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('mousedown', onPointer);
            root?.removeEventListener('focusout', onFocusOut);
        };
    }, [open]);

    return (
        <li className="relative" ref={rootRef}>
            <button
                ref={triggerRef}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((value) => !value)}
                className={`flex min-h-11 items-center gap-1 rounded-control px-3 text-sm font-semibold hover:bg-white/10 hover:text-white ${
                    containsCurrent ? 'bg-white/10 text-white' : 'text-white/85'
                }`}
            >
                Channels
                <LineIcon name="chevron-down" className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && (
                <div
                    id={panelId}
                    className="absolute left-0 z-30 mt-2 w-72 rounded-card border border-border bg-white py-2 text-body shadow-elevated"
                >
                    <ul>
                        {channels.map((channel) => {
                            const href = `/${channel.slug}`;
                            const current = isCurrentPath(currentPath, href);
                            const meta = channelMeta(channel.slug);

                            return (
                                <li key={channel.slug}>
                                    <Link
                                        href={href}
                                        aria-current={current ? 'page' : undefined}
                                        onClick={() => setOpen(false)}
                                        className={`flex min-h-11 items-center gap-3 px-4 py-2 text-sm hover:bg-brand-mist ${
                                            current ? 'font-bold text-brand-ink' : 'text-muted hover:text-body'
                                        }`}
                                    >
                                        <LineIcon name={meta?.icon ?? 'document'} className={`h-5 w-5 shrink-0 ${meta?.textClass ?? 'text-teal-deep'}`} />
                                        {channel.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </li>
    );
}

export default function PublicLayout({ children }: { children: ReactNode }) {
    const page = usePage<SharedPageProps>();
    const channels = page.props.publicChannels ?? [];
    const currentPath = (page.url ?? '/').split(/[?#]/)[0] || '/';
    const [menuOpen, setMenuOpen] = useState(false);
    const menuId = useId();
    const mobileChannelsHeadingId = useId();
    const menuTriggerRef = useRef<HTMLButtonElement>(null);
    const mobileMenuRef = useRef<HTMLDivElement>(null);
    const desktopNavigationRef = useRef<HTMLDivElement>(null);
    const focusMobileMenuTrigger = useCallback(() => menuTriggerRef.current?.focus(), []);
    const focusFirstDesktopDestination = useCallback(
        () => desktopNavigationRef.current?.querySelector<HTMLElement>('a[href]')?.focus(),
        [],
    );

    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;

        const desktopQuery = window.matchMedia('(min-width: 64rem)');
        const handleBreakpointChange = (event: MediaQueryListEvent) => {
            if (event.matches) {
                const activeElement = document.activeElement;
                const mobileNavigationHadFocus =
                    menuTriggerRef.current === activeElement ||
                    (mobileMenuRef.current?.contains(activeElement) ?? false);
                if (menuOpen) setMenuOpen(false);
                if (mobileNavigationHadFocus) focusFirstDesktopDestination();
                return;
            }

            const desktopNavigationHadFocus = desktopNavigationRef.current?.contains(document.activeElement) ?? false;
            if (desktopNavigationHadFocus) focusMobileMenuTrigger();
        };

        desktopQuery.addEventListener('change', handleBreakpointChange);
        return () => desktopQuery.removeEventListener('change', handleBreakpointChange);
    }, [focusFirstDesktopDestination, focusMobileMenuTrigger, menuOpen]);

    useEffect(() => {
        if (!menuOpen) return;

        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
                menuTriggerRef.current?.focus();
            }
        };

        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [menuOpen]);

    return (
        <div className="public-gradient-shell text-on-glass">
            <a href="#main-content" className="skip-link">
                Skip to main content
            </a>
            <header className="glass-panel sticky top-0 z-40 border-b border-white/10">
                <div className="mx-auto flex h-16 max-w-public items-center justify-between gap-6 px-5 sm:px-6">
                    <Link href="/" className="inline-flex shrink-0 rounded-control py-1">
                        <ApesLogo variant="masthead" className="h-12 w-auto object-contain" />
                    </Link>

                    <div ref={desktopNavigationRef} className="hidden items-center gap-5 lg:flex">
                        <nav aria-label="Primary navigation">
                            <ul className="flex items-center gap-1">
                                <li>
                                    <Link
                                        href="/"
                                        aria-current={currentPath === '/' ? 'page' : undefined}
                                        className={`flex min-h-11 items-center rounded-control px-3 text-sm font-semibold hover:bg-white/10 hover:text-white ${
                                            currentPath === '/' ? 'text-white' : 'text-white/85'
                                        }`}
                                    >
                                        Home
                                    </Link>
                                </li>
                                {channels.length > 0 && (
                                    <ChannelsMenu channels={channels} currentPath={currentPath} />
                                )}
                                <li>
                                    <Link
                                        href="/search"
                                        aria-label="Search"
                                        className="flex min-h-11 min-w-11 items-center justify-center rounded-full text-white/85 hover:bg-white/10 hover:text-white"
                                    >
                                        <LineIcon name="search" className="h-5 w-5" />
                                    </Link>
                                </li>
                            </ul>
                        </nav>
                        <AccountMenu
                            tone="dark"
                            breakpoint="desktop"
                            onHiddenWhileFocused={focusMobileMenuTrigger}
                        />
                    </div>

                    <button
                        ref={menuTriggerRef}
                        type="button"
                        className="icon-button border border-white/25 text-white lg:hidden"
                        aria-label={menuOpen ? 'Close main menu' : 'Open main menu'}
                        aria-expanded={menuOpen}
                        aria-controls={menuId}
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        <LineIcon name={menuOpen ? 'x' : 'menu'} className="h-5 w-5" />
                    </button>
                </div>

                {menuOpen && (
                    <div
                        ref={mobileMenuRef}
                        id={menuId}
                        className="glass-panel max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain border-t border-white/10 px-5 py-4 lg:hidden"
                    >
                        <nav aria-label="Primary navigation">
                            <ul className="mx-auto flex max-w-public flex-col gap-1">
                                <li>
                                    <Link
                                        href="/"
                                        aria-current={currentPath === '/' ? 'page' : undefined}
                                        className="flex min-h-11 items-center rounded-control px-3 text-sm font-semibold text-white aria-[current=page]:bg-white/10"
                                        onClick={() => setMenuOpen(false)}
                                    >
                                        Home
                                    </Link>
                                </li>
                                {channels.length > 0 && (
                                    <li className="border-t border-white/10 pt-2">
                                        <p id={mobileChannelsHeadingId} className="px-3 pb-1 text-xs font-bold tracking-wide text-white/60 uppercase">
                                            Channels
                                        </p>
                                        <ul aria-labelledby={mobileChannelsHeadingId} className="flex flex-col gap-1">
                                            {channels.map((channel) => {
                                                const href = `/${channel.slug}`;
                                                const meta = channelMeta(channel.slug);

                                                return (
                                                    <li key={channel.slug}>
                                                        <Link
                                                            href={href}
                                                            aria-current={isCurrentPath(currentPath, href) ? 'page' : undefined}
                                                            className="flex min-h-11 items-center gap-3 rounded-control px-3 text-sm font-semibold text-white aria-[current=page]:bg-white/10"
                                                            onClick={() => setMenuOpen(false)}
                                                        >
                                                            <LineIcon name={meta?.icon ?? 'document'} className="h-4 w-4 shrink-0 text-white/70" />
                                                            {channel.label}
                                                        </Link>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </li>
                                )}
                                <li className={channels.length > 0 ? 'border-t border-white/10 pt-2' : undefined}>
                                    <Link
                                        href="/search"
                                        className="flex min-h-11 items-center gap-2 rounded-control px-3 text-sm font-semibold text-white"
                                        onClick={() => setMenuOpen(false)}
                                    >
                                        <LineIcon name="search" className="h-4 w-4" />
                                        Search
                                    </Link>
                                </li>
                            </ul>
                        </nav>
                        <div className="mx-auto mt-3 max-w-public border-t border-white/15 pt-4">
                            <AccountMenu
                                tone="dark"
                                breakpoint="mobile"
                                onHiddenWhileFocused={focusFirstDesktopDestination}
                            />
                        </div>
                    </div>
                )}
            </header>
            {children}
            <SiteFooter />
        </div>
    );
}
