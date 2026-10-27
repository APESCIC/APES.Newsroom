import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, ExternalLink, LogOut, Menu, Search, TriangleAlert, UserCircle, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import type { SharedPageProps } from '../../types/page';
import ApesLogo from '../Brand/ApesLogo';
import CommandPalette from '../Workspace/CommandPalette';
import { areaHome, findNavItem, visibleGroups, type WorkspaceActive, type WorkspaceArea } from '../Workspace/navigation';
import { useDialogFocus } from '../Workspace/useDialogFocus';
import Toast from '../Workspace/ui/Toast';

export type { WorkspaceActive } from '../Workspace/navigation';

function shortcutLabel(): string {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K';
}

function BrandMark({ area }: { area: WorkspaceArea }) {
    return (
        <Link href="/" className="flex min-w-0 items-center gap-2.5 rounded-full">
            <ApesLogo variant="mark" alt="" className="h-10 w-auto shrink-0 drop-shadow-sm" />
            <span className="min-w-0 leading-tight">
                <span className="block text-[0.9375rem] font-bold tracking-tight text-body">APES Newsroom</span>
                <span className="block text-xs text-muted">{area} panel</span>
            </span>
        </Link>
    );
}

function PanelSwitch({ area }: { area: WorkspaceArea }) {
    const { auth } = usePage<SharedPageProps>().props;
    if (!auth.can.accessStaff || !auth.can.accessAdmin) return null;

    return (
        <nav aria-label="Switch panel" className="sg-switch mt-5">
            {(['Staff', 'Admin'] as const).map((panel) => (
                <Link key={panel} href={areaHome(panel)} aria-current={area === panel ? 'true' : undefined}>
                    {panel}
                </Link>
            ))}
        </nav>
    );
}

function WorkspaceNavigation({ area, active }: { area: WorkspaceArea; active: WorkspaceActive }) {
    const { auth } = usePage<SharedPageProps>().props;
    const headingId = useId();

    return (
        <nav aria-label={`${area} workspace`} className="mt-5 flex flex-col gap-4">
            {visibleGroups(area, auth.can).map((group, index) => (
                <div key={group.heading}>
                    <p id={`${headingId}-${index}`} className="sg-nav-heading">
                        {group.heading}
                    </p>
                    <ul aria-labelledby={`${headingId}-${index}`} className="mt-1.5 flex flex-col gap-0.5">
                        {group.items.map((item) => (
                            <li key={item.key}>
                                <Link href={item.href} aria-current={item.key === active ? 'page' : undefined} className="sg-nav-link">
                                    <item.icon aria-hidden="true" className="h-[1.125rem] w-[1.125rem] shrink-0" />
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

function UserCard() {
    const { auth } = usePage<SharedPageProps>().props;
    if (!auth.user) return null;

    const initials = auth.user.name
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    return (
        <div className="mt-auto pt-5">
            <div className="flex items-center gap-3 rounded-2xl bg-white/50 p-3">
                <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--color-sg-teal)] text-xs font-bold text-white">
                    {initials}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-semibold text-body">{auth.user.name}</span>
                    <span className="block text-xs text-muted capitalize">{auth.user.role.replace('_', ' ')}</span>
                </span>
            </div>
            <div className="mt-2 grid grid-cols-2 gap-1">
                <Link href="/account" className="sg-btn sg-btn-ghost sg-btn-sm">
                    <UserCircle aria-hidden="true" className="h-4 w-4" />
                    Account
                </Link>
                <Link href="/logout" method="post" as="button" className="sg-btn sg-btn-ghost sg-btn-sm">
                    <LogOut aria-hidden="true" className="h-4 w-4" />
                    Sign out
                </Link>
            </div>
        </div>
    );
}

function Sidebar({ area, active, close }: { area: WorkspaceArea; active: WorkspaceActive; close?: () => void }) {
    return (
        <div className="sg-rail flex h-full flex-col overflow-y-auto overscroll-contain p-4" data-testid="workspace-sidebar">
            <div className="flex items-center justify-between gap-2">
                <BrandMark area={area} />
                {close && (
                    <button type="button" className="sg-icon-btn" aria-label="Close workspace navigation" data-workspace-close onClick={close}>
                        <X aria-hidden="true" className="h-5 w-5" />
                    </button>
                )}
            </div>
            <PanelSwitch area={area} />
            <WorkspaceNavigation area={area} active={active} />
            <UserCard />
        </div>
    );
}

function Breadcrumbs({ area, active, title }: { area: WorkspaceArea; active: WorkspaceActive; title: string }) {
    const section = findNavItem(area, active);
    const crumbs: { label: string; href?: string }[] = [{ label: area, href: areaHome(area) }];
    if (section && section.label !== title) crumbs.push({ label: section.label, href: section.href });
    crumbs.push({ label: title });

    return (
        <nav aria-label="Breadcrumb" className="min-w-0">
            <ol className="flex flex-wrap items-center gap-1 text-sm text-muted">
                {crumbs.map((crumb, index) => (
                    <li key={`${crumb.label}-${index}`} className="flex items-center gap-1">
                        {index > 0 && <ChevronRight aria-hidden="true" className="h-3.5 w-3.5" />}
                        {crumb.href && index < crumbs.length - 1 ? (
                            <Link href={crumb.href} className="hover:text-body hover:underline">
                                {crumb.label}
                            </Link>
                        ) : (
                            <span aria-current="page" className="font-medium text-body">
                                {crumb.label}
                            </span>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}

function SendingDomainAlertBanner({ active }: { active: WorkspaceActive }) {
    const { sendingDomainAlerts = [] } = usePage<SharedPageProps>().props;
    if (sendingDomainAlerts.length === 0) return null;

    return (
        <div role="alert" className="sg-card mb-4 flex gap-3 border-l-4 !border-l-[var(--color-sg-error)] p-4" data-testid="sending-domain-alert">
            <TriangleAlert aria-hidden="true" className="h-5 w-5 shrink-0 text-danger" />
            <div className="text-sm text-body">
                <p className="font-semibold text-danger">Newsletter sending is blocked for a failing email domain</p>
                <ul className="mt-1 list-disc pl-5">
                    {sendingDomainAlerts.map((alert) => (
                        <li key={alert.domain}>
                            <span className="font-semibold">{alert.domain}</span> (used by {alert.newsletters.join(', ')})
                            {alert.message ? `: ${alert.message}` : ''}
                        </li>
                    ))}
                </ul>
                {active !== 'email-domains' && (
                    <Link href="/admin/settings/email-domains" className="mt-2 inline-block font-semibold underline">
                        Review email sending domains
                    </Link>
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
    active: WorkspaceActive;
    title: string;
    subtitle?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { auth, flash } = usePage<SharedPageProps>().props;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const navigationId = useId();
    const navigationTriggerRef = useRef<HTMLButtonElement>(null);
    const navigationDialogRef = useRef<HTMLElement>(null);
    const desktopSidebarRef = useRef<HTMLElement>(null);
    const closedAtDesktopRef = useRef(false);

    useDialogFocus(mobileOpen, navigationDialogRef, () => setMobileOpen(false), {
        initialFocus: '[data-workspace-close]',
        fallbackFocus: navigationTriggerRef,
        lockScroll: true,
    });

    useEffect(() => {
        if (mobileOpen || !closedAtDesktopRef.current) return;
        closedAtDesktopRef.current = false;
        desktopSidebarRef.current?.querySelector<HTMLElement>('[aria-current="page"]')?.focus();
    }, [mobileOpen]);

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
        const openPalette = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setMobileOpen(false);
                setPaletteOpen(true);
            }
        };

        document.addEventListener('keydown', openPalette);
        return () => document.removeEventListener('keydown', openPalette);
    }, []);

    const overlayOpen = mobileOpen || paletteOpen;

    return (
        <div data-testid="workspace-shell" className="workspace-shell">
            <div data-testid="workspace-background" inert={overlayOpen ? true : undefined} aria-hidden={overlayOpen ? true : undefined}>
                <a href="#main-content" className="skip-link">
                    Skip to main content
                </a>
                <aside ref={desktopSidebarRef} className="sg-rail-dock hidden lg:block">
                    <Sidebar area={area} active={active} />
                </aside>
                <div className="sg-main-column">
                    <div className="mx-auto w-full max-w-panel px-4 pt-4 pb-16 sm:px-6 lg:px-8 lg:pt-6">
                        <header data-testid="workspace-task-header" className="mb-6 flex flex-col gap-4">
                            <div className="sg-card flex items-center justify-between gap-3 px-3 py-2 lg:hidden">
                                <button
                                    ref={navigationTriggerRef}
                                    type="button"
                                    className="sg-icon-btn"
                                    aria-label="Open workspace navigation"
                                    aria-expanded={mobileOpen}
                                    aria-controls={navigationId}
                                    onClick={() => setMobileOpen(true)}
                                >
                                    <Menu aria-hidden="true" className="h-5 w-5" />
                                </button>
                                <BrandMark area={area} />
                                <button type="button" className="sg-icon-btn" aria-label="Search pages and actions" onClick={() => setPaletteOpen(true)}>
                                    <Search aria-hidden="true" className="h-5 w-5" />
                                </button>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <Breadcrumbs area={area} active={active} title={title} />
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        className="sg-search-pill hidden w-64 lg:inline-flex"
                                        aria-keyshortcuts="Control+K Meta+K"
                                        onClick={() => setPaletteOpen(true)}
                                    >
                                        <Search aria-hidden="true" className="h-4 w-4" />
                                        <span className="flex-1 text-left">Search</span>
                                        <kbd aria-hidden="true" className="sg-kbd">
                                            {shortcutLabel()}
                                        </kbd>
                                    </button>
                                    <a href="/" className="sg-btn sg-btn-secondary sg-btn-sm">
                                        <ExternalLink aria-hidden="true" className="h-4 w-4" />
                                        View site
                                    </a>
                                </div>
                            </div>
                            <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                                <div className="min-w-0">
                                    <h1 className="text-panel-title font-bold tracking-tight text-body">{title}</h1>
                                    {subtitle && <p className="mt-1 text-muted">{subtitle}</p>}
                                </div>
                                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                            </div>
                        </header>
                        {area === 'Admin' && <SendingDomainAlertBanner active={active} />}
                        <Toast message={flash?.status} />
                        <main id="main-content" tabIndex={-1} data-testid="workspace-canvas" className="flex flex-col gap-6 focus:outline-none">
                            {children}
                        </main>
                    </div>
                </div>
            </div>
            {mobileOpen && (
                <div className="sg-overlay lg:hidden" onClick={() => setMobileOpen(false)}>
                    <aside
                        ref={navigationDialogRef}
                        id={navigationId}
                        className="h-full w-[min(18rem,88vw)] p-3"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Workspace navigation"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <Sidebar area={area} active={active} close={() => setMobileOpen(false)} />
                    </aside>
                </div>
            )}
            <CommandPalette open={paletteOpen} can={auth.can} onClose={() => setPaletteOpen(false)} />
        </div>
    );
}
