import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { channelMeta } from '../../../channelMeta';
import LineIcon from '../../../Components/Icons/LineIcon';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type Post = {
    id: number;
    title: string;
    slug: string;
    status: string;
    channel: string;
    updated_at: string | null;
    author: string;
};

function postStatus(status: string) {
    const labels: Record<string, { label: string; className: string }> = {
        draft: { label: 'Draft', className: 'bg-brand-mist text-teal-deep' },
        in_review: { label: 'In review', className: 'bg-warning-mist text-warning' },
        published: { label: 'Published', className: 'bg-success-mist text-success' },
        archived: { label: 'Archived', className: 'bg-page-tint text-muted' },
    };

    return labels[status] ?? { label: status.replaceAll('_', ' '), className: 'bg-page-tint text-muted' };
}

function formatDate(value: string | null) {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '—';
}

function StatusBadge({ status }: { status: string }) {
    const meta = postStatus(status);
    return (
        <span className={`inline-flex min-h-8 items-center rounded-full px-3 py-1 text-xs font-bold tracking-wide ${meta.className}`}>
            {meta.label}
        </span>
    );
}

function ChannelLabel({ value }: { value: string }) {
    const meta = channelMeta(value);
    return (
        <span
            className={`inline-flex min-h-8 items-center rounded-full border px-2.5 py-1 text-[0.625rem] font-bold tracking-wide uppercase ${
                meta?.badgeClass ?? 'border-border bg-page-tint text-muted'
            }`}
        >
            {meta?.label ?? value.replaceAll('_', ' ')}
        </span>
    );
}

export default function PostsIndex({
    posts,
    filterStatus,
    filterChannel = null,
    filters = { q: '' },
    channels = [],
    canReview,
}: {
    posts: Post[];
    filterStatus: string | null;
    filterChannel?: string | null;
    filters?: { q: string };
    channels?: Array<{ value: string; label: string }>;
    canReview: boolean;
}) {
    const [search, setSearch] = useState(filters.q ?? '');

    const statusQuery = (status: string | null) => {
        const params = new URLSearchParams();
        if (status) params.set('status', status);
        if (filterChannel) params.set('channel', filterChannel);
        if (filters.q) params.set('q', filters.q);
        const qs = params.toString();
        return qs ? `/staff/posts?${qs}` : '/staff/posts';
    };

    const filtersNav = [
        { href: statusQuery(null), value: null, label: 'All' },
        { href: statusQuery('in_review'), value: 'in_review', label: 'In review' },
        { href: statusQuery('published'), value: 'published', label: 'Published' },
    ];

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/staff/posts',
            {
                status: filterStatus || undefined,
                channel: filterChannel || undefined,
                q: search || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const onChannelChange = (value: string) => {
        router.get(
            '/staff/posts',
            {
                status: filterStatus || undefined,
                channel: value || undefined,
                q: search || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const actions = (
        <div className="flex flex-wrap gap-3">
            {canReview && (
                <Link href="/staff/posts/review" className="button-secondary">
                    <LineIcon name="review" className="h-4 w-4" />
                    Review queue
                </Link>
            )}
            <Link href="/staff/posts/new" className="button-primary">
                <LineIcon name="plus" className="h-4 w-4" />
                New draft
            </Link>
        </div>
    );

    return (
        <WorkspaceLayout
            area="Staff"
            active="posts"
            title="Posts"
            subtitle="Manage and publish editorial content"
            actions={actions}
        >
            <Head title="Staff — Posts" />
            <main id="main-content" className="mx-auto max-w-[62.5rem] px-5 py-6 sm:px-6">
                <form onSubmit={applyFilters} className="mb-4 grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end" role="search" aria-label="Search and filter posts">
                    <label className="text-sm font-semibold text-body">
                        Search title or author
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="form-input mt-2"
                            placeholder="Title or author"
                        />
                    </label>
                    <label className="text-sm font-semibold text-body">
                        Channel
                        <select
                            className="form-input mt-2"
                            value={filterChannel ?? ''}
                            onChange={(event) => onChannelChange(event.target.value)}
                        >
                            <option value="">All channels</option>
                            {channels.map((channel) => (
                                <option key={channel.value} value={channel.value}>
                                    {channel.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <button type="submit" className="button-primary min-h-11">
                        Apply
                    </button>
                </form>

                <nav aria-label="Post status filters" className="workspace-glass-tabs rounded-control px-2">
                    <ul className="flex gap-1 overflow-x-auto">
                        {filtersNav.map((filter) => {
                            const active = filterStatus === filter.value;
                            return (
                                <li key={filter.label}>
                                    <Link
                                        href={filter.href}
                                        aria-current={active ? 'page' : undefined}
                                        className={`flex min-h-11 items-center border-b-2 px-4 py-3 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus)] ${
                                            active ? 'border-teal-deep text-teal-deep' : 'border-transparent text-muted hover:text-body'
                                        }`}
                                    >
                                        {filter.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                {posts.length > 0 ? (
                    <>
                        <div className="workspace-glass-panel mt-6 hidden md:block">
                            <table aria-label="Newsroom posts" className="w-full text-left text-sm">
                                <thead className="bg-brand-mist/40 text-xs tracking-wide text-muted uppercase">
                                    <tr>
                                        <th scope="col" className="px-5 py-3 font-bold">Title</th>
                                        <th scope="col" className="px-3 py-3 font-bold">Status</th>
                                        <th scope="col" className="px-3 py-3 font-bold">Channel</th>
                                        <th scope="col" className="px-5 py-3 font-bold">Updated</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border/70">
                                    {posts.map((post) => (
                                        <tr
                                            key={post.id}
                                            className={`bg-white/35 hover:bg-brand-mist/35 ${post.status === 'in_review' ? 'border-l-4 border-warning' : ''}`}
                                        >
                                            <td className="px-5 py-3.5">
                                                <Link
                                                    href={`/staff/posts/${post.id}/edit`}
                                                    aria-label={`Edit ${post.title}`}
                                                    className="font-bold text-brand-ink hover:text-teal-deep hover:underline"
                                                >
                                                    {post.title}
                                                </Link>
                                                <p className="mt-1 text-xs text-muted">By {post.author}</p>
                                            </td>
                                            <td className="px-3 py-3.5"><StatusBadge status={post.status} /></td>
                                            <td className="px-3 py-3.5"><ChannelLabel value={post.channel} /></td>
                                            <td className="px-5 py-3.5 text-muted">{formatDate(post.updated_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul aria-label="Newsroom posts on small screens" className="mt-6 space-y-3 md:hidden">
                            {posts.map((post) => (
                                <li
                                    key={post.id}
                                    className={`workspace-glass-card p-4 ${post.status === 'in_review' ? 'border-l-4 border-l-warning' : ''}`}
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <StatusBadge status={post.status} />
                                        <span className="text-xs text-muted">{formatDate(post.updated_at)}</span>
                                    </div>
                                    <h2 className="mt-3 text-base font-bold text-brand-ink">
                                        <Link href={`/staff/posts/${post.id}/edit`} className="hover:text-teal-deep hover:underline">{post.title}</Link>
                                    </h2>
                                    <p className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
                                        <ChannelLabel value={post.channel} />
                                        <span>· {post.author}</span>
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </>
                ) : (
                    <div className="workspace-glass-card mt-6 p-10 text-center">
                        <LineIcon name="document" className="mx-auto h-10 w-10 text-teal-deep" />
                        <h2 className="mt-4 text-xl font-bold text-brand-ink">No posts found</h2>
                        <p className="mt-2 text-muted">Try another status filter or start a new draft.</p>
                    </div>
                )}
            </main>
        </WorkspaceLayout>
    );
}
