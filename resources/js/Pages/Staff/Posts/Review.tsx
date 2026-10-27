import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck, FileText } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import { ChannelLabel } from '../../../Components/Staff/PostBadges';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import PillButton from '../../../Components/Workspace/ui/PillButton';

type Post = {
    id: number;
    title: string;
    slug: string;
    status: string;
    channel: string;
    updated_at: string | null;
    author: string;
    review_notes?: string | null;
};

export default function ReviewQueue({ posts }: { posts: Post[] }) {
    return (
        <WorkspaceLayout
            area="Staff"
            active="review"
            title="Review queue"
            subtitle="Posts submitted for review, oldest changes last"
            actions={
                <PillButton href="/staff/posts" variant="secondary" icon={FileText}>
                    All posts
                </PillButton>
            }
        >
            <Head title="Review queue" />
            {posts.length === 0 ? (
                <EmptyState icon={ClipboardCheck} title="Nothing to review" description="No posts are awaiting review." />
            ) : (
                <DataTable
                    label="Posts awaiting review"
                    rows={posts}
                    rowKey={(post) => post.id}
                    columns={[
                        {
                            key: 'title',
                            header: 'Title',
                            primary: true,
                            cell: (post) => (
                                <>
                                    <Link href={`/staff/posts/${post.id}/edit`} className="font-semibold text-body hover:text-teal-deep hover:underline">
                                        {post.title}
                                    </Link>
                                    {post.review_notes && <p className="mt-0.5 text-xs text-muted">{post.review_notes}</p>}
                                </>
                            ),
                        },
                        { key: 'author', header: 'Author', cell: (post) => post.author },
                        { key: 'channel', header: 'Channel', cell: (post) => <ChannelLabel value={post.channel} /> },
                        {
                            key: 'updated',
                            header: 'Updated',
                            cell: (post) => (
                                <span className="text-muted">{post.updated_at ? new Date(post.updated_at).toLocaleDateString('en-GB') : '—'}</span>
                            ),
                        },
                    ]}
                />
            )}
        </WorkspaceLayout>
    );
}
