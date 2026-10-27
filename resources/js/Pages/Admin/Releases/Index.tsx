import { Head, Link, router } from '@inertiajs/react';
import { Plus, Rocket } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import PillButton from '../../../Components/Workspace/ui/PillButton';

type ReleaseRow = {
    id: number;
    version: string;
    released_at: string | null;
    channel: string;
    is_current: boolean;
    is_published: boolean;
    slug: string;
    theme: string | null;
};

export default function ReleasesIndex({ releases }: { releases: ReleaseRow[] }) {
    return (
        <WorkspaceLayout
            area="Admin"
            active="releases"
            title="Release notes"
            subtitle="Author Change Log Hub entries for public visitors."
            actions={
                <PillButton href="/admin/releases/new" icon={Plus}>
                    New release
                </PillButton>
            }
        >
            <Head title="Release notes" />
            {releases.length === 0 ? (
                <EmptyState icon={Rocket} title="No release notes yet" description="Create the first entry for the Change Log Hub." />
            ) : (
                <DataTable
                    label="Release notes"
                    rows={releases}
                    rowKey={(release) => release.id}
                    columns={[
                        {
                            key: 'version',
                            header: 'Version',
                            primary: true,
                            cell: (release) => (
                                <span className="inline-flex items-center gap-2 font-semibold text-body">
                                    {release.version}
                                    {release.is_current && <Badge tone="info">Current</Badge>}
                                </span>
                            ),
                        },
                        { key: 'date', header: 'Date', cell: (release) => <span className="text-muted">{release.released_at ?? '—'}</span> },
                        { key: 'channel', header: 'Channel', cell: (release) => <span className="capitalize">{release.channel}</span> },
                        {
                            key: 'status',
                            header: 'Status',
                            cell: (release) => (release.is_published ? <Badge tone="ok">Published</Badge> : <Badge tone="neutral">Draft</Badge>),
                        },
                        {
                            key: 'actions',
                            header: 'Actions',
                            cell: (release) => (
                                <span className="flex flex-wrap gap-3">
                                    <Link href={`/admin/releases/${release.id}/edit`} className="font-medium text-body underline">
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        className="font-medium text-danger underline"
                                        onClick={() => {
                                            if (confirm(`Delete release ${release.version}?`)) {
                                                router.delete(`/admin/releases/${release.id}`);
                                            }
                                        }}
                                    >
                                        Delete
                                    </button>
                                </span>
                            ),
                        },
                    ]}
                />
            )}
        </WorkspaceLayout>
    );
}
