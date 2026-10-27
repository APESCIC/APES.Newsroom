import { Link } from '@inertiajs/react';
import { Eye, EyeOff, ExternalLink, FileStack, Pencil, Plus, Trash2 } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import { PostStatusBadge } from '../../../Components/Staff/PostBadges';
import { useConfirmedAction } from '../../../Components/Workspace/useConfirmedAction';
import ActionMenu, { type MenuAction } from '../../../Components/Workspace/ui/ActionMenu';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import ListSearch from '../../../Components/Workspace/ui/ListSearch';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';
import PillButton from '../../../Components/Workspace/ui/PillButton';
import Tabs from '../../../Components/Workspace/ui/Tabs';

type PageRow = {
    id: number;
    title: string;
    slug: string;
    status: string;
    updated_at: string | null;
    author: string;
};

function formatDate(value: string | null) {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '—';
}

function listUrl(status: string | null, q: string) {
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (q) params.set('q', q);
    const qs = params.toString();
    return qs ? `/staff/pages?${qs}` : '/staff/pages';
}

export default function PagesIndex({
    pages,
    filterStatus,
    filters = { q: '' },
}: {
    pages: PageMeta & { data: PageRow[] };
    filterStatus: string | null;
    filters?: { q: string };
}) {
    const { confirm, dialog } = useConfirmedAction();

    const tabs = [
        { value: null, label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'published', label: 'Published' },
        { value: 'unpublished', label: 'Unpublished' },
    ].map((tab) => ({
        label: tab.label,
        href: listUrl(tab.value, filters.q),
        active: filterStatus === tab.value,
    }));

    const rowActions = (page: PageRow): MenuAction[] => [
        { label: 'Edit', icon: Pencil, href: `/staff/pages/${page.id}/edit` },
        {
            label: 'Preview',
            icon: Eye,
            href: `/staff/pages/${page.id}/preview`,
        },
        ...(page.status === 'published'
            ? [
                  {
                      label: 'View on site',
                      icon: ExternalLink,
                      href: `/pages/${page.slug}`,
                  },
                  {
                      label: 'Unpublish',
                      icon: EyeOff,
                      onSelect: () =>
                          confirm({
                              title: 'Unpublish this page?',
                              description: `“${page.title}” will be taken off the public site until it is published again.`,
                              confirmLabel: 'Unpublish page',
                              method: 'post',
                              url: `/staff/pages/${page.id}/unpublish`,
                          }),
                  },
              ]
            : []),
        {
            label: 'Delete',
            icon: Trash2,
            tone: 'danger',
            onSelect: () =>
                confirm({
                    title: 'Delete this page?',
                    description: `“${page.title}” will be taken off the site and removed from this list.`,
                    confirmLabel: 'Delete page',
                    method: 'delete',
                    url: `/staff/pages/${page.id}`,
                }),
        },
    ];

    const newPage = (
        <PillButton href="/staff/pages/new" icon={Plus}>
            New page
        </PillButton>
    );

    const filtered = filterStatus !== null || filters.q !== '';

    return (
        <WorkspaceLayout area="Staff" active="pages" title="Pages" subtitle="Static pages distinct from news articles" actions={newPage}>
            <ListSearch path="/staff/pages" label="Search pages" placeholder="Title or slug" initial={filters.q} params={{ status: filterStatus }} />
            <Tabs label="Page status filters" items={tabs} className="self-start" />
            {pages.data.length === 0 ? (
                filtered ? (
                    <EmptyState icon={FileStack} title="No pages found" description="Try another status filter or search." />
                ) : (
                    <EmptyState
                        icon={FileStack}
                        title="No pages yet"
                        description="Pages hold content such as About or Volunteer that stays out of news lists."
                        action={newPage}
                    />
                )
            ) : (
                <>
                    <DataTable
                        label="Pages"
                        rows={pages.data}
                        rowKey={(page) => page.id}
                        columns={[
                            {
                                key: 'title',
                                header: 'Title',
                                primary: true,
                                cell: (page) => (
                                    <>
                                        <Link href={`/staff/pages/${page.id}/edit`} className="font-semibold text-body hover:text-teal-deep hover:underline">
                                            {page.title}
                                        </Link>
                                        <p className="mt-0.5 text-xs text-muted">/pages/{page.slug}</p>
                                    </>
                                ),
                            },
                            {
                                key: 'status',
                                header: 'Status',
                                cell: (page) => <PostStatusBadge status={page.status} />,
                            },
                            {
                                key: 'author',
                                header: 'Author',
                                cell: (page) => page.author,
                            },
                            {
                                key: 'updated',
                                header: 'Updated',
                                cell: (page) => <span className="text-muted">{formatDate(page.updated_at)}</span>,
                            },
                            {
                                key: 'actions',
                                header: <span className="sr-only">Actions</span>,
                                label: 'Actions',
                                className: 'text-right',
                                cell: (page) => <ActionMenu label={`Actions for ${page.title}`} actions={rowActions(page)} />,
                            },
                        ]}
                    />
                    <Pagination page={pages} label="Pages" />
                </>
            )}
            {dialog}
        </WorkspaceLayout>
    );
}
