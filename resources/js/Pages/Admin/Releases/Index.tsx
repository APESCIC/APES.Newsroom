import { Head, Link, router, usePage } from '@inertiajs/react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

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
    const { flash } = usePage().props as { flash?: { status?: string | null } };

    return (
        <WorkspaceLayout
            area="Admin"
            active="releases"
            title="Release notes"
            subtitle="Author Change Log Hub entries for public visitors."
            actions={
                <Link href="/admin/releases/new" className="button-primary inline-flex min-h-11 items-center px-4">
                    New release
                </Link>
            }
        >
            <Head title="Release notes" />
            {flash?.status && <p className="mb-4 text-sm text-green-800">{flash.status}</p>}

            {releases.length === 0 ? (
                <p className="rounded-card border border-border bg-white p-8 text-center text-muted">
                    No release notes yet. Create the first entry for the Change Log Hub.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-card border border-border bg-white">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-border bg-brand-ink/5 text-xs tracking-wide uppercase">
                            <tr>
                                <th className="px-4 py-3">Version</th>
                                <th className="px-4 py-3">Date</th>
                                <th className="px-4 py-3">Channel</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {releases.map((release) => (
                                <tr key={release.id} className="border-b border-border/70 last:border-0">
                                    <td className="px-4 py-3 font-semibold">
                                        {release.version}
                                        {release.is_current && (
                                            <span className="ml-2 rounded bg-brand-teal/30 px-2 py-0.5 text-[0.65rem] font-bold tracking-wide uppercase">
                                                Current
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted">{release.released_at ?? '—'}</td>
                                    <td className="px-4 py-3 capitalize">{release.channel}</td>
                                    <td className="px-4 py-3">{release.is_published ? 'Published' : 'Draft'}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-3">
                                            <Link href={`/admin/releases/${release.id}/edit`} className="text-brand-ink underline">
                                                Edit
                                            </Link>
                                            <button
                                                type="button"
                                                className="text-red-700 underline"
                                                onClick={() => {
                                                    if (confirm(`Delete release ${release.version}?`)) {
                                                        router.delete(`/admin/releases/${release.id}`);
                                                    }
                                                }}
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </WorkspaceLayout>
    );
}
