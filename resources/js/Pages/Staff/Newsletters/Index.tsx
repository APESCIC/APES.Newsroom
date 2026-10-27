import { Link, router } from '@inertiajs/react';
import { Archive, ArchiveRestore, ExternalLink, Mail, Pencil, Plus } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import { useConfirmedAction } from '../../../Components/Workspace/useConfirmedAction';
import ActionMenu, { type MenuAction } from '../../../Components/Workspace/ui/ActionMenu';
import Badge from '../../../Components/Workspace/ui/Badge';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import ListSearch from '../../../Components/Workspace/ui/ListSearch';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';
import PillButton from '../../../Components/Workspace/ui/PillButton';
import Tabs from '../../../Components/Workspace/ui/Tabs';

type NewsletterStatus = 'active' | 'archived';
type StatusFilter = 'all' | NewsletterStatus;

type NewsletterRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    legacy_list: string | null;
    status: NewsletterStatus;
    sender_name: string | null;
    sender_email: string | null;
    sender_verified: boolean;
    channel: string | null;
    show_on_home: boolean;
    show_in_nav: boolean;
    display_order: number;
    confirmed_count: number;
    pending_count: number;
    unsubscribed_count: number;
    account_confirmed_count: number;
    mailing_only_confirmed_count: number;
};

const FILTERS: Array<{ value: StatusFilter; label: string }> = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'archived', label: 'Archived' },
];

function StatusBadge({ status }: { status: NewsletterStatus }) {
    switch (status) {
        case 'active':
            return <Badge tone="ok">Active</Badge>;
        case 'archived':
            return <Badge tone="neutral">Archived</Badge>;
        default: {
            const unreachable: never = status;
            return unreachable;
        }
    }
}

function listUrl(status: StatusFilter, q: string) {
    const params = new URLSearchParams();
    if (status !== 'all') params.set('status', status);
    if (q) params.set('q', q);
    const qs = params.toString();
    return qs ? `/staff/newsletters?${qs}` : '/staff/newsletters';
}

export default function NewslettersIndex({
    newsletters,
    status,
    filters = { q: '' },
}: {
    newsletters: PageMeta & { data: NewsletterRow[] };
    status: StatusFilter;
    filters?: { q: string };
}) {
    const { confirm, dialog } = useConfirmedAction();

    const rowActions = (newsletter: NewsletterRow): MenuAction[] => [
        {
            label: 'Edit',
            icon: Pencil,
            href: `/staff/newsletters/${newsletter.id}/edit`,
        },
        ...(newsletter.status === 'active'
            ? [
                  {
                      label: 'View signup page',
                      icon: ExternalLink,
                      href: `/newsletters/${newsletter.slug}/signup`,
                  },
                  {
                      label: 'Archive',
                      icon: Archive,
                      tone: 'danger' as const,
                      onSelect: () =>
                          confirm({
                              title: 'Archive this newsletter?',
                              description: `“${newsletter.name}” will stop taking signups and cannot be sent to. Subscribers are kept, and you can restore it later.`,
                              confirmLabel: 'Archive newsletter',
                              method: 'post',
                              url: `/staff/newsletters/${newsletter.id}/archive`,
                          }),
                  },
              ]
            : [
                  {
                      label: 'Restore',
                      icon: ArchiveRestore,
                      onSelect: () => router.post(`/staff/newsletters/${newsletter.id}/restore`, {}, { preserveScroll: true }),
                  },
              ]),
    ];

    const newNewsletter = (
        <PillButton href="/staff/newsletters/new" icon={Plus}>
            New newsletter
        </PillButton>
    );

    return (
        <WorkspaceLayout
            area="Staff"
            active="newsletters"
            title="Newsletters"
            subtitle="Every newsletter members can subscribe to, including the three channel lists"
            actions={newNewsletter}
        >
            <ListSearch
                path="/staff/newsletters"
                label="Search newsletters"
                placeholder="Name, slug or sender email"
                initial={filters.q}
                params={{ status: status === 'all' ? null : status }}
            />
            <Tabs
                label="Filter newsletters by status"
                className="self-start"
                items={FILTERS.map((filter) => ({
                    label: filter.label,
                    href: listUrl(filter.value, filters.q),
                    active: status === filter.value,
                }))}
            />
            {newsletters.data.length === 0 ? (
                <EmptyState
                    icon={Mail}
                    title="No newsletters here"
                    description="Try another filter or search, or create a newsletter."
                    action={newNewsletter}
                />
            ) : (
                <>
                    <ul className="sg-card divide-y divide-border" aria-label="Newsletters">
                        {newsletters.data.map((newsletter) => (
                            <li key={newsletter.id} className="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center md:justify-between">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link
                                            href={`/staff/newsletters/${newsletter.id}/edit`}
                                            className="font-semibold text-body hover:text-teal-deep hover:underline"
                                        >
                                            {newsletter.name}
                                        </Link>
                                        <StatusBadge status={newsletter.status} />
                                        {newsletter.channel && newsletter.status === 'active' && (
                                            <Badge tone="info">
                                                Public channel #{newsletter.display_order}
                                                {newsletter.show_on_home ? ' · home' : ''}
                                                {newsletter.show_in_nav ? ' · menu' : ''}
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-1 text-sm break-words text-muted">
                                        /{newsletter.slug}
                                        {newsletter.legacy_list ? ` · channel list ${newsletter.legacy_list}` : ''}
                                        {newsletter.sender_email
                                            ? ` · from ${newsletter.sender_name ?? newsletter.sender_email} <${newsletter.sender_email}>`
                                            : ' · no sender set'}
                                        {newsletter.sender_email ? (newsletter.sender_verified ? ' · sender verified' : ' · sender not verified') : ''}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-3">
                                    <p className="text-sm text-body md:text-right">
                                        <span className="font-semibold">{newsletter.confirmed_count} confirmed</span>{' '}
                                        <span className="text-muted">
                                            ({newsletter.account_confirmed_count} account · {newsletter.mailing_only_confirmed_count} mailing-only)
                                        </span>
                                        <br />
                                        <span className="text-muted">
                                            {newsletter.pending_count} pending · {newsletter.unsubscribed_count} unsubscribed
                                        </span>
                                    </p>
                                    <ActionMenu label={`Actions for ${newsletter.name}`} actions={rowActions(newsletter)} />
                                </div>
                            </li>
                        ))}
                    </ul>
                    <Pagination page={newsletters} label="Newsletters" />
                </>
            )}
            {dialog}
        </WorkspaceLayout>
    );
}
