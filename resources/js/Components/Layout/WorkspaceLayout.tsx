import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import type { SharedPageProps } from '../../types/page';
import ApesLogo from '../Brand/ApesLogo';
import LineIcon, { type IconName } from '../Icons/LineIcon';

type WorkspaceArea = 'Admin' | 'Staff';

type WorkspaceLink = {
    href: string;
    label: string;
    icon: IconName;
    active: boolean;
};

function WorkspaceNavigation({ area, active }: { area: WorkspaceArea; active: 'moderation' | 'posts' | 'pages' | 'newsletters' | 'releases' }) {
    const { auth } = usePage<SharedPageProps>().props;
    const links: WorkspaceLink[] = [];

    if (area === 'Staff' && auth.can.accessStaff) {
        links.push({ href: '/staff/posts', label: 'Posts', icon: 'document', active: active === 'posts' });
        links.push({ href: '/staff/pages', label: 'Pages', icon: 'document', active: active === 'pages' });
        links.push({ href: '/staff/newsletters', label: 'Newsletters', icon: 'document', active: active === 'newsletters' });
    }

    if (area === 'Admin' && auth.can.accessAdmin) {
        links.push({ href: '/admin/moderation', label: 'Moderation', icon: 'shield', active: active === 'moderation' });
        links.push({ href: '/admin/releases', label: 'Releases', icon: 'document', active: active === 'releases' });
    }

    return (
        <nav aria-label={`${area} workspace`} className="flex flex-col gap-2">
            {links.map((link) => (
                <Link
                    key={link.href}
                    href={link.href}
                    aria-current={link.active ? 'page' : undefined}
                    className={`flex min-h-11 items-center gap-3 rounded-control border-l-4 px-3 py-2 text-sm font-semibold transition-colors ${
                        link.active
                            ? 'border-brand-teal bg-brand-teal text-brand-ink'
                            : 'border-transparent text-white hover:bg-white/10'
                    }`}
                >
                    <LineIcon name={link.icon} className="h-5 w-5 shrink-0" />
                    {link.label}
                </Link>
            ))}
            {area === 'Staff' && auth.can.accessAdmin && (
                <Link
                    href="/admin/moderation"
                    className="flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold text-white hover:bg-white/10"
                >
                    <LineIcon name="shield" className="h-5 w-5 shrink-0" />
                    Admin panel
                </Link>
            )}
        </nav>
    );
}

function Sidebar({ area, active, close }: { area: WorkspaceArea; active: 'moderation' | 'posts' | 'pages' | 'newsletters' | 'releases'; close?: () => void }) {
    const { auth } = usePage<SharedPageProps>().props;
    const roleLabel = auth.user?.role.replace('_', ' ') ?? 'workspace';

    return (
        <div
            className="flex h-full flex-col overflow-y-auto overscroll-contain bg-brand-ink px-5 py-6 text-white"
            data-testid="workspace-sidebar"
        >
            <div className="flex items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <Link href="/" className="inline-flex rounded-control">
                        <ApesLogo variant="compact" className="h-10 w-10 object-contain" />
                    </Link>
                    <span className="rounded bg-white/10 px-2 py-1 text-[0.625rem] font-bold tracking-widest text-white/75 uppercase">{area}</span>
                </div>
                {close && (
                    <button
                        type="button"
                        className="icon-button text-white"
                        aria-label="Close workspace navigation"
                        data-workspace-close
                        onClick={close}
                    >
                        <LineIcon name="x" className="h-5 w-5" />
                    </button>
                )}
            </div>
            <div className="mt-6">
                <WorkspaceNavigation area={area} active={active} />
            </div>
            <div className="mt-auto border-t border-white/15 pt-5">
                <Link href="/" className="flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm text-white hover:bg-white/10">
                    <LineIcon name="home" className="h-5 w-5" />
                    Back to Newsroom
                </Link>
                {auth.user && (
                    <div className="mt-4 border-t border-white/15 pt-4">
                        <p className="truncate text-sm font-semibold">{auth.user.name}</p>
                        <p className="mt-1 text-xs text-white/65 capitalize">{roleLabel}</p>
                        <div className="mt-3 flex gap-4 text-sm">
                            <Link href="/account" className="min-h-11 py-2 text-white/80 hover:text-white">
                                Account
                            </Link>
                            <Link href="/logout" method="post" as="button" className="min-h-11 py-2 text-white/80 hover:text-white">
                                Sign out
                            </Link>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function WorkspaceLayout({
    area,
    active,
    title,
    subtitle,
    actions,
    children,
}: {
    area: WorkspaceArea;
    active: 'moderation' | 'posts' | 'pages' | 'newsletters' | 'releases';
    title: string;
    subtitle?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const [mobileOpen, setMobileOpen] = useState(false);
    const navigationId = useId();
    const workspaceShellRef = useRef<HTMLDivElement>(null);
    const taskHeaderRef = useRef<HTMLElement>(null);
    const navigationTriggerRef = useRef<HTMLButtonElement>(null);
    const navigationDialogRef = useRef<HTMLElement>(null);
    const desktopSidebarRef = useRef<HTMLElement>(null);
    const closedAtDesktopRef = useRef(false);

    useEffect(() => {
        const workspaceShell = workspaceShellRef.current;
        const taskHeader = taskHeaderRef.current;
        if (!workspaceShell || !taskHeader) return;

        const updateTaskHeaderHeight = () => {
            workspaceShell.style.setProperty(
                '--workspace-task-header-height',
                `${Math.ceil(taskHeader.getBoundingClientRect().height)}px`,
            );
        };

        updateTaskHeaderHeight();

        if (typeof ResizeObserver === 'undefined') {
            window.addEventListener('resize', updateTaskHeaderHeight);
            return () => window.removeEventListener('resize', updateTaskHeaderHeight);
        }

        const resizeObserver = new ResizeObserver(updateTaskHeaderHeight);
        resizeObserver.observe(taskHeader);
        return () => resizeObserver.disconnect();
    }, []);

    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;

        const desktopQuery = window.matchMedia('(min-width: 64rem)');
        const closeAtDesktop = (event: MediaQueryListEvent) => {
            if (event.matches && mobileOpen) {
                closedAtDesktopRef.current = true;
                setMobileOpen(false);
            }
        };

        desktopQuery.addEventListener('change', closeAtDesktop);
        return () => desktopQuery.removeEventListener('change', closeAtDesktop);
    }, [mobileOpen]);

    useEffect(() => {
        if (!mobileOpen) return;

        const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const navigationTrigger = navigationTriggerRef.current;
        const dialog = navigationDialogRef.current;
        const desktopSidebar = desktopSidebarRef.current;
        const previousBodyOverflow = document.body.style.overflow;
        const focusableSelector =
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        const focusableElements = () =>
            dialog
                ? Array.from(dialog.querySelectorAll<HTMLElement>(focusableSelector)).filter(
                      (element) => !element.hasAttribute('hidden') && element.getAttribute('aria-hidden') !== 'true',
                  )
                : [];

        document.body.style.overflow = 'hidden';
        (dialog?.querySelector<HTMLElement>('[data-workspace-close]') ?? focusableElements()[0])?.focus();

        const handleDialogKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMobileOpen(false);
                return;
            }

            if (event.key !== 'Tab') return;

            const elements = focusableElements();
            if (elements.length === 0) {
                event.preventDefault();
                return;
            }

            const first = elements[0];
            const last = elements[elements.length - 1];
            const activeElement = document.activeElement;

            if (event.shiftKey && (activeElement === first || !dialog?.contains(activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleDialogKeyDown);
        return () => {
            document.removeEventListener('keydown', handleDialogKeyDown);
            document.body.style.overflow = previousBodyOverflow;
            if (closedAtDesktopRef.current) {
                closedAtDesktopRef.current = false;
                desktopSidebar?.querySelector<HTMLElement>('[aria-current="page"]')?.focus();
            } else if (previouslyFocused?.isConnected) previouslyFocused.focus();
            else navigationTrigger?.focus();
        };
    }, [mobileOpen]);

    return (
        <div
            ref={workspaceShellRef}
            data-testid="workspace-shell"
            className="workspace-shell min-h-screen bg-page-tint text-body"
        >
            <div
                data-testid="workspace-background"
                inert={mobileOpen ? true : undefined}
                aria-hidden={mobileOpen ? true : undefined}
            >
                <a href="#main-content" className="skip-link">
                    Skip to main content
                </a>
                <aside ref={desktopSidebarRef} className="fixed inset-y-0 left-0 hidden w-64 lg:block">
                    <Sidebar area={area} active={active} />
                </aside>
                <header className="flex min-h-16 items-center justify-between border-b border-border bg-white px-5 lg:hidden">
                    <Link href="/" className="inline-flex items-center gap-3 font-semibold text-brand-ink">
                        <ApesLogo variant="compact" alt="" className="h-11 w-11 object-contain" />
                        APES Newsroom
                    </Link>
                    <button
                        ref={navigationTriggerRef}
                        type="button"
                        className="icon-button border border-border text-brand-ink"
                        aria-label="Open workspace navigation"
                        aria-expanded={mobileOpen}
                        aria-controls={navigationId}
                        onClick={() => setMobileOpen(true)}
                    >
                        <LineIcon name="menu" className="h-5 w-5" />
                    </button>
                </header>
                <div className="lg:pl-64">
                    <header
                        ref={taskHeaderRef}
                        data-testid="workspace-task-header"
                        className="sticky top-0 z-30 flex min-h-16 flex-col items-start justify-between gap-3 border-b border-border bg-white px-5 py-3 sm:flex-row sm:items-center sm:px-6"
                    >
                        <div>
                            <h1 className="text-lg font-bold text-body">{title}</h1>
                            {subtitle && <p className="text-xs text-muted">{subtitle}</p>}
                        </div>
                        {actions}
                    </header>
                    {children}
                </div>
            </div>
            {mobileOpen && (
                <div className="fixed inset-0 z-50 bg-brand-ink/45 lg:hidden" onClick={() => setMobileOpen(false)}>
                    <aside
                        ref={navigationDialogRef}
                        id={navigationId}
                        className="h-full w-[min(20rem,88vw)]"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Workspace navigation"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <Sidebar area={area} active={active} close={() => setMobileOpen(false)} />
                    </aside>
                </div>
            )}
        </div>
    );
}
