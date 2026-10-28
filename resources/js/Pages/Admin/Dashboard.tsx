import { Link } from '@inertiajs/react';
import { Flag, MailCheck, MessageSquare, Plug, Rocket, ScrollText, Settings, ShieldCheck, UserCog, UserX, type LucideIcon } from 'lucide-react';
import WorkspaceLayout from '../../Components/Layout/WorkspaceLayout';
import GlassCard from '../../Components/Workspace/ui/GlassCard';
import StatTile, { StatGrid } from '../../Components/Workspace/ui/StatTile';

type Props = {
    moderation: { profiles: number; comments: number; reports: number; suspended: number };
    release: { version: string; summary: string | null; released_at: string | null } | null;
    audit: Array<{ id: number; action: string; actor: string | null; subject_type: string | null; created_at: string | null }> | null;
};

const shortcuts: Array<{ href: string; label: string; description: string; icon: LucideIcon }> = [
    { href: '/admin/settings', label: 'Settings', description: 'Site-wide configuration', icon: Settings },
    { href: '/admin/settings/integrations', label: 'Integrations & keys', description: 'Mailgun, Stripe and other providers', icon: Plug },
    { href: '/admin/settings/email-domains', label: 'Sending domains', description: 'Newsletter email DNS checks', icon: MailCheck },
    { href: '/admin/users', label: 'Users', description: 'Accounts, roles and security', icon: UserCog },
];

function formatDateTime(value: string | null): string {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
}

function formatDate(value: string | null): string {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '—';
}

export default function AdminDashboard({ moderation, release, audit }: Props) {
    return (
        <WorkspaceLayout area="Admin" active="dashboard" title="Dashboard" subtitle="Moderation, system status and recent admin activity">
            <section aria-labelledby="dashboard-moderation" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="dashboard-moderation" className="text-lg font-semibold text-body">
                        Moderation
                    </h2>
                    <Link href="/admin/moderation" className="text-sm font-semibold text-teal-deep hover:underline">
                        Open moderation queue
                    </Link>
                </div>
                <StatGrid label="Moderation summary">
                    <StatTile label="Profiles" value={moderation.profiles} hint="Pending review" icon={ShieldCheck} href="/admin/moderation" />
                    <StatTile label="Comments" value={moderation.comments} hint="Pending review" icon={MessageSquare} href="/admin/moderation" />
                    <StatTile label="Reports" value={moderation.reports} hint="Open" icon={Flag} href="/admin/moderation" />
                    <StatTile label="Suspended" value={moderation.suspended} hint="Profiles" icon={UserX} href="/admin/moderation" />
                </StatGrid>
            </section>

            <div className="grid gap-4 lg:grid-cols-2">
                <GlassCard
                    as="section"
                    aria-label="Latest release"
                    title={
                        <span className="flex items-center gap-2">
                            <Rocket aria-hidden="true" className="h-4 w-4 text-teal-deep" />
                            Latest release
                        </span>
                    }
                    actions={
                        <a href="/change-log-hub" className="text-sm font-semibold text-teal-deep hover:underline">
                            Change Log Hub
                        </a>
                    }
                >
                    {release ? (
                        <div className="flex flex-col gap-1">
                            <p className="text-xl font-bold text-body">{release.version}</p>
                            <p className="text-xs text-muted">Released {formatDate(release.released_at)}</p>
                            {release.summary && <p className="mt-2 text-sm text-body">{release.summary}</p>}
                        </div>
                    ) : (
                        <p className="text-sm text-muted">No release has been published yet.</p>
                    )}
                </GlassCard>

                <GlassCard as="section" aria-label="Settings shortcuts" title="Settings shortcuts">
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {shortcuts.map((shortcut) => (
                            <li key={shortcut.href}>
                                <Link href={shortcut.href} className="flex h-full items-start gap-3 rounded-2xl bg-white/50 p-3 hover:bg-white/80">
                                    <shortcut.icon aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0 text-teal-deep" />
                                    <span>
                                        <span className="block text-sm font-semibold text-body">{shortcut.label}</span>
                                        <span className="block text-xs text-muted">{shortcut.description}</span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </GlassCard>
            </div>

            {audit && (
                <GlassCard
                    as="section"
                    aria-label="Recent audit events"
                    title={
                        <span className="flex items-center gap-2">
                            <ScrollText aria-hidden="true" className="h-4 w-4 text-teal-deep" />
                            Recent audit events
                        </span>
                    }
                    actions={
                        <Link href="/admin/audit-log" className="text-sm font-semibold text-teal-deep hover:underline">
                            Full audit log
                        </Link>
                    }
                >
                    {audit.length === 0 ? (
                        <p className="text-sm text-muted">No audit events recorded yet.</p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-white/60">
                            {audit.map((event) => (
                                <li key={event.id} className="flex flex-wrap items-baseline justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                                    <span className="min-w-0">
                                        <code className="text-sm font-semibold text-body">{event.action}</code>
                                        <span className="block text-xs text-muted">
                                            {event.actor ?? 'System'}
                                            {event.subject_type ? ` · ${event.subject_type}` : ''}
                                        </span>
                                    </span>
                                    <span className="text-xs text-muted">{formatDateTime(event.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </GlassCard>
            )}
        </WorkspaceLayout>
    );
}
