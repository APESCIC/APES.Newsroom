import { Link } from '@inertiajs/react';
import { FileStack, Plus } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import { PostStatusBadge } from '../../../Components/Staff/PostBadges';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
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

export default function PagesIndex({ pages, filterStatus }: { pages: PageRow[]; filterStatus: string | null }) {
    const tabs = [
        { href: '/staff/pages', value: null, label: 'All' },
        { href: '/staff/pages?status=draft', value: 'draft', label: 'Draft' },
        { href: '/staff/pages?status=published', value: 'published', label: 'Published' },
    ].map((tab) => ({ label: tab.label, href: tab.href, active: filterStatus === tab.value }));

    const newPage = (
        <PillButton href="/staff/pages/new" icon={Plus}>
            New page
        </PillButton>
    );

    return (
        <WorkspaceLayout area="Staff" active="pages" title="Pages" subtitle="Static pages distinct from news articles" actions={newPage}>
            <Tabs label="Page status filters" items={tabs} className="self-start" />
            {pages.length === 0 ? (
                <EmptyState icon={FileStack} title="No pages yet" description="Pages hold content such as About or Volunteer that stays out of news lists." action={newPage} />
            ) : (
                <DataTable
                    label="Pages"
                    rows={pages}
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
                        { key: 'status', header: 'Status', cell: (page) => <PostStatusBadge status={page.status} /> },
                        { key: 'author', header: 'Author', cell: (page) => page.author },
                        { key: 'updated', header: 'Updated', cell: (page) => <span className="text-muted">{formatDate(page.updated_at)}</span> },
                    ]}
                />
            )}
        </WorkspaceLayout>
    );
}
