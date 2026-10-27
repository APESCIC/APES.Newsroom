import { Link, router } from '@inertiajs/react';
import { ClipboardCheck, ExternalLink, Eye, EyeOff, FileText, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import { ChannelLabel, PostStatusBadge } from '../../../Components/Staff/PostBadges';
import { useConfirmedAction } from '../../../Components/Workspace/useConfirmedAction';
import ActionMenu, { type MenuAction } from '../../../Components/Workspace/ui/ActionMenu';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';
import PillButton from '../../../Components/Workspace/ui/PillButton';
import Tabs from '../../../Components/Workspace/ui/Tabs';

type Post = {
    id: number;
    title: string;
    slug: string;
    status: string;
    channel: string;
    updated_at: string | null;
    author: string;
};

function formatDate(value: string | null) {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '—';
}

export default function PostsIndex({
    posts,
    filterStatus,
    filterChannel = null,
    filters = { q: '' },
    channels = [],
    canReview,
    canPublish = false,
}: {
    posts: PageMeta & { data: Post[] };
    filterStatus: string | null;
    filterChannel?: string | null;
    filters?: { q: string };
    channels?: Array<{ value: string; label: string }>;
    canReview: boolean;
    canPublish?: boolean;
}) {
    const [search, setSearch] = useState(filters.q ?? '');
    const { confirm, dialog } = useConfirmedAction();

    const rowActions = (post: Post): MenuAction[] => [
        { label: 'Edit', icon: Pencil, href: `/staff/posts/${post.id}/edit` },
        {
            label: 'Preview',
            icon: Eye,
            href: `/staff/posts/${post.id}/preview`,
        },
        ...(post.status === 'published'
            ? [
                  {
                      label: 'View on site',
                      icon: ExternalLink,
                      href: `/articles/${post.slug}`,
                  },
              ]
            : []),
        ...(canPublish && (post.status === 'published' || post.status === 'scheduled')
            ? [
                  {
                      label: post.status === 'scheduled' ? 'Cancel schedule' : 'Unpublish',
                      icon: EyeOff,
                      onSelect: () =>
                          confirm({
                              title: post.status === 'scheduled' ? 'Cancel this scheduled post?' : 'Unpublish this post?',
                              description: `“${post.title}” will be taken off the public site and its schedule cleared. You can publish it again later.`,
                              confirmLabel: post.status === 'scheduled' ? 'Cancel schedule' : 'Unpublish post',
                              method: 'post',
                              url: `/staff/posts/${post.id}/unpublish`,
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
                    title: 'Delete this post?',
                    description: `“${post.title}” will be soft deleted and removed from this list.`,
                    confirmLabel: 'Delete post',
                    method: 'delete',
                    url: `/staff/posts/${post.id}`,
                }),
        },
    ];

    const statusQuery = (status: string | null) => {
        const params = new URLSearchParams();
        if (status) params.set('status', status);
        if (filterChannel) params.set('channel', filterChannel);
        if (filters.q) params.set('q', filters.q);
        const qs = params.toString();
        return qs ? `/staff/posts?${qs}` : '/staff/posts';
    };

    const tabs = [
        { value: null, label: 'All' },
        { value: 'draft', label: 'Drafts' },
        { value: 'in_review', label: 'In review' },
        { value: 'scheduled', label: 'Scheduled' },
        { value: 'published', label: 'Published' },
    ].map((tab) => ({
        label: tab.label,
        href: statusQuery(tab.value),
        active: filterStatus === tab.value,
    }));

    const visit = (overrides: { channel?: string | null }) => {
        const channel = overrides.channel !== undefined ? overrides.channel : filterChannel;
        router.get(
            '/staff/posts',
            {
                status: filterStatus || undefined,
                channel: channel || undefined,
                q: search || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        visit({});
    };

    const actions = (
        <>
            {canReview && (
                <PillButton href="/staff/posts/review" variant="secondary" icon={ClipboardCheck}>
                    Review queue
                </PillButton>
            )}
            <PillButton href="/staff/posts/new" icon={Plus}>
                New draft
            </PillButton>
        </>
    );

    return (
        <WorkspaceLayout area="Staff" active="posts" title="Posts" subtitle="Manage and publish editorial content" actions={actions}>
            <form
                onSubmit={applyFilters}
                className="sg-card grid gap-3 p-4 sm:grid-cols-[1fr_14rem_auto] sm:items-end"
                role="search"
                aria-label="Search and filter posts"
            >
                <label className="text-sm font-semibold text-body">
                    Search title or author
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="form-input mt-1.5"
                        placeholder="Title or author"
                    />
                </label>
                <label className="text-sm font-semibold text-body">
                    Channel
                    <select className="form-input mt-1.5" value={filterChannel ?? ''} onChange={(event) => visit({ channel: event.target.value })}>
                        <option value="">All channels</option>
                        {channels.map((channel) => (
                            <option key={channel.value} value={channel.value}>
                                {channel.label}
                            </option>
                        ))}
                    </select>
                </label>
                <button type="submit" className="sg-btn sg-btn-primary">
                    Apply
                </button>
            </form>

            <Tabs label="Post status filters" items={tabs} className="self-start" />

            {posts.data.length > 0 ? (
                <>
                    <DataTable
                        label="Newsroom posts"
                        rows={posts.data}
                        rowKey={(post) => post.id}
                        rowClassName={(post) => (post.status === 'in_review' ? 'shadow-[inset_4px_0_0_var(--color-sg-warn)]' : '')}
                        columns={[
                            {
                                key: 'title',
                                header: 'Title',
                                primary: true,
                                cell: (post) => (
                                    <>
                                        <Link
                                            href={`/staff/posts/${post.id}/edit`}
                                            aria-label={`Edit ${post.title}`}
                                            className="font-semibold text-body hover:text-teal-deep hover:underline"
                                        >
                                            {post.title}
                                        </Link>
                                        <p className="mt-0.5 text-xs text-muted">By {post.author}</p>
                                    </>
                                ),
                            },
                            {
                                key: 'status',
                                header: 'Status',
                                cell: (post) => <PostStatusBadge status={post.status} />,
                            },
                            {
                                key: 'channel',
                                header: 'Channel',
                                cell: (post) => <ChannelLabel value={post.channel} />,
                            },
                            {
                                key: 'updated',
                                header: 'Updated',
                                cell: (post) => <span className="text-muted">{formatDate(post.updated_at)}</span>,
                            },
                            {
                                key: 'actions',
                                header: <span className="sr-only">Actions</span>,
                                label: 'Actions',
                                className: 'text-right',
                                cell: (post) => <ActionMenu label={`Actions for ${post.title}`} actions={rowActions(post)} />,
                            },
                        ]}
                    />
                    <Pagination page={posts} label="Posts" />
                </>
            ) : (
                <EmptyState
                    icon={FileText}
                    title="No posts found"
                    description="Try another status filter or start a new draft."
                    action={
                        <PillButton href="/staff/posts/new" icon={Plus}>
                            New draft
                        </PillButton>
                    }
                />
            )}
            {dialog}
        </WorkspaceLayout>
    );
}
