import { Head, Link } from '@inertiajs/react';
import LineIcon from '../../../Components/Icons/LineIcon';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type PageRow = {
    id: number;
    title: string;
    slug: string;
    status: string;
    updated_at: string | null;
    author: string;
};

function pageStatus(status: string) {
    const labels: Record<string, { label: string; className: string }> = {
        draft: { label: 'Draft', className: 'bg-brand-mist text-teal-deep' },
        published: { label: 'Published', className: 'bg-success-mist text-success' },
        unpublished: { label: 'Unpublished', className: 'bg-page-tint text-muted' },
    };

    return labels[status] ?? { label: status.replaceAll('_', ' '), className: 'bg-page-tint text-muted' };
}

function formatDate(value: string | null) {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '—';
}

export default function PagesIndex({
    pages,
    filterStatus,
}: {
    pages: PageRow[];
    filterStatus: string | null;
}) {
    const filters = [
        { href: '/staff/pages', value: null, label: 'All' },
        { href: '/staff/pages?status=draft', value: 'draft', label: 'Draft' },
        { href: '/staff/pages?status=published', value: 'published', label: 'Published' },
    ];

    return (
        <WorkspaceLayout
            area="Staff"
            active="pages"
            title="Pages"
            subtitle="Static pages distinct from news articles"
            actions={
                <Link href="/staff/pages/new" className="button-primary">
                    <LineIcon name="plus" className="h-4 w-4" />
                    New page
                </Link>
            }
        >
            <Head title="Pages" />
            <div className="mb-4 flex flex-wrap gap-2">
                {filters.map((filter) => (
                    <Link
                        key={filter.label}
                        href={filter.href}
                        className={`rounded-control border px-3 py-1 text-sm ${
                            filterStatus === filter.value
                                ? 'border-teal-deep bg-brand-mist text-teal-deep'
                                : 'border-border text-muted hover:border-teal-deep'
                        }`}
                    >
                        {filter.label}
                    </Link>
                ))}
            </div>
            <div className="overflow-x-auto rounded-control border border-border bg-surface">
                <table className="min-w-full text-left text-sm">
                    <thead className="border-b border-border bg-page-tint text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th className="px-4 py-3">Title</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3">Author</th>
                            <th className="px-4 py-3">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pages.length === 0 ? (
                            <tr>
                                <td colSpan={4} className="px-4 py-8 text-center text-muted">
                                    No pages yet.
                                </td>
                            </tr>
                        ) : (
                            pages.map((page) => {
                                const meta = pageStatus(page.status);
                                return (
                                    <tr key={page.id} className="border-b border-border last:border-0">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/staff/pages/${page.id}/edit`}
                                                className="font-semibold hover:text-teal-deep hover:underline"
                                            >
                                                {page.title}
                                            </Link>
                                            <div className="text-xs text-muted">/pages/{page.slug}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold ${meta.className}`}>
                                                {meta.label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">{page.author}</td>
                                        <td className="px-4 py-3">{formatDate(page.updated_at)}</td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>
        </WorkspaceLayout>
    );
}
