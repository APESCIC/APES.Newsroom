import { Head, Link, router } from '@inertiajs/react';
import { useState, type KeyboardEvent } from 'react';
import LineIcon from '../../../Components/Icons/LineIcon';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type PendingProfile = {
    id: number;
    display_name: string | null;
    bio: string | null;
    user_name: string;
    updated_at: string | null;
};

type PendingComment = {
    id: number;
    body: string;
    user_name: string;
    post_title: string;
    post_slug: string;
    created_at: string | null;
};

type Report = {
    id: number;
    reason: string;
    reportable_type: string;
    reportable_id: number;
    reporter: string | null;
    created_at: string | null;
};

type Suspended = {
    id: number;
    display_name: string | null;
    user_name: string;
    notes: string | null;
};

type Queue = 'profiles' | 'comments' | 'reports' | 'suspended';

function formatDate(value: string | null) {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : 'Date unavailable';
}

function EmptyQueue({ children }: { children: string }) {
    return (
        <p className="workspace-glass-card p-8 text-center text-muted" data-testid="moderation-empty">
            {children}
        </p>
    );
}

export default function ModerationIndex({
    profiles,
    comments,
    reports = [],
    suspended = [],
}: {
    profiles: PendingProfile[];
    comments: PendingComment[];
    reports?: Report[];
    suspended?: Suspended[];
}) {
    const [activeQueue, setActiveQueue] = useState<Queue>('profiles');

    const moderateProfile = (id: number, status: string) => {
        router.post(`/admin/moderation/profiles/${id}`, { status });
    };

    const moderateComment = (id: number, status: string) => {
        router.post(`/admin/moderation/comments/${id}`, { status });
    };

    const queues: Array<{ id: Queue; tabLabel: string; summaryLabel: string; count: number }> = [
        { id: 'profiles', tabLabel: 'Profiles', summaryLabel: 'Profiles awaiting review', count: profiles.length },
        { id: 'comments', tabLabel: 'Comments', summaryLabel: 'Comments awaiting review', count: comments.length },
        { id: 'reports', tabLabel: 'Reports', summaryLabel: 'Open reports', count: reports.length },
        { id: 'suspended', tabLabel: 'Suspended', summaryLabel: 'Suspended profiles', count: suspended.length },
    ];

    const handleTabKey = (event: KeyboardEvent<HTMLButtonElement>, queue: Queue) => {
        const currentIndex = queues.findIndex((item) => item.id === queue);
        let nextIndex: number | null = null;

        if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % queues.length;
        if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + queues.length) % queues.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = queues.length - 1;

        if (nextIndex === null) return;

        event.preventDefault();
        const nextQueue = queues[nextIndex].id;
        setActiveQueue(nextQueue);
        document.getElementById(`tab-${nextQueue}`)?.focus();
    };

    return (
        <WorkspaceLayout area="Admin" active="moderation" title="Moderation queue">
            <Head title="Moderation" />
            <main id="main-content" className="mx-auto max-w-workspace px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Moderation summary">
                    {queues.map((queue) => (
                        <button
                            key={queue.id}
                            type="button"
                            className={`workspace-glass-card min-h-32 p-5 text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus)] ${
                                activeQueue === queue.id ? '' : 'hover:border-teal-deep'
                            }`}
                            onClick={() => setActiveQueue(queue.id)}
                            aria-label={`${queue.summaryLabel}: ${queue.count}`}
                            aria-pressed={activeQueue === queue.id}
                        >
                            <span className="block text-3xl font-bold text-brand-ink">{queue.count}</span>
                            <span className="mt-3 block text-sm font-semibold text-muted">{queue.summaryLabel}</span>
                        </button>
                    ))}
                </section>

                <section className="workspace-glass-panel mt-8" aria-label="Moderation queue records">
                    <div className="workspace-glass-tabs px-2 sm:px-4">
                        <div role="tablist" aria-label="Moderation queues" className="flex gap-1 overflow-x-auto">
                            {queues.map((queue) => (
                                <button
                                    key={queue.id}
                                    id={`tab-${queue.id}`}
                                    type="button"
                                    role="tab"
                                    aria-selected={activeQueue === queue.id}
                                    aria-controls={`panel-${queue.id}`}
                                    tabIndex={activeQueue === queue.id ? 0 : -1}
                                    className={`min-h-11 shrink-0 border-b-2 px-4 py-3 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus)] ${
                                        activeQueue === queue.id
                                            ? 'border-teal-deep text-teal-deep'
                                            : 'border-transparent text-muted hover:text-body'
                                    }`}
                                    onClick={() => setActiveQueue(queue.id)}
                                    onKeyDown={(event) => handleTabKey(event, queue.id)}
                                >
                                    {queue.tabLabel} ({queue.count})
                                </button>
                            ))}
                        </div>
                    </div>

                    <div
                        id="panel-profiles"
                        role="tabpanel"
                        aria-labelledby="tab-profiles"
                        hidden={activeQueue !== 'profiles'}
                        className="p-5 sm:p-6"
                    >
                        {profiles.length > 0 ? (
                                <>
                                    <div className="hidden overflow-x-auto md:block">
                                        <table className="w-full text-left text-sm" aria-label="Pending profiles">
                                            <thead className="bg-brand-mist/50 text-xs tracking-wide text-muted uppercase">
                                                <tr>
                                                    <th scope="col" className="px-5 py-4">User / account</th>
                                                    <th scope="col" className="px-5 py-4">Bio / status</th>
                                                    <th scope="col" className="px-5 py-4">Updated</th>
                                                    <th scope="col" className="px-5 py-4 text-right">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-border/70">
                                                {profiles.map((profile) => {
                                                    const name = profile.display_name ?? 'Untitled profile';
                                                    return (
                                                        <tr key={profile.id} className="bg-white/40 hover:bg-brand-mist/30">
                                                            <td className="px-5 py-5">
                                                                <p className="font-bold text-body">{name}</p>
                                                                <p className="mt-1 text-xs text-muted">{profile.user_name}</p>
                                                            </td>
                                                            <td className="max-w-sm px-5 py-5 text-body">{profile.bio ?? 'No bio supplied.'}</td>
                                                            <td className="px-5 py-5 text-xs text-muted">{formatDate(profile.updated_at)}</td>
                                                            <td className="px-5 py-5">
                                                                <div className="flex justify-end gap-2">
                                                                    <button type="button" className="button-success" aria-label={`Approve profile for ${name}`} onClick={() => moderateProfile(profile.id, 'approved')}>Approve</button>
                                                                    <button type="button" className="button-danger" aria-label={`Reject profile for ${name}`} onClick={() => moderateProfile(profile.id, 'rejected')}>Reject</button>
                                                                    <button type="button" className="button-secondary" aria-label={`Suspend profile for ${name}`} onClick={() => moderateProfile(profile.id, 'suspended')}>Suspend</button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                    <ul className="space-y-4 md:hidden">
                                        {profiles.map((profile) => {
                                            const name = profile.display_name ?? 'Untitled profile';
                                            return (
                                                <li key={profile.id} className="workspace-glass-card p-5">
                                                    <div className="flex items-start gap-4">
                                                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-mist text-teal-deep">
                                                            <LineIcon name="user" className="h-5 w-5" />
                                                        </span>
                                                        <div>
                                                            <h3 className="font-bold text-brand-ink">{name}</h3>
                                                            <p className="mt-1 text-sm text-muted">Account: {profile.user_name}</p>
                                                        </div>
                                                    </div>
                                                    {profile.bio && <p className="mt-5 text-sm leading-6 text-body">{profile.bio}</p>}
                                                    <div className="mt-5 flex flex-wrap gap-2">
                                                        <button type="button" className="button-success" aria-label={`Approve profile for ${name} on small screens`} onClick={() => moderateProfile(profile.id, 'approved')}>Approve</button>
                                                        <button type="button" className="button-danger" aria-label={`Reject profile for ${name} on small screens`} onClick={() => moderateProfile(profile.id, 'rejected')}>Reject</button>
                                                        <button type="button" className="button-secondary" aria-label={`Suspend profile for ${name} on small screens`} onClick={() => moderateProfile(profile.id, 'suspended')}>Suspend</button>
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </>
                        ) : <EmptyQueue>No profiles are waiting for review.</EmptyQueue>}
                    </div>

                    <div
                        id="panel-comments"
                        role="tabpanel"
                        aria-labelledby="tab-comments"
                        hidden={activeQueue !== 'comments'}
                        className="p-5 sm:p-6"
                    >
                        {comments.length > 0 ? (
                                <ul className="space-y-4">
                                    {comments.map((comment) => (
                                        <li key={comment.id} className="workspace-glass-card p-6">
                                            <p className="text-sm text-muted">
                                                <strong className="text-body">{comment.user_name}</strong> on{' '}
                                                <Link href={`/articles/${comment.post_slug}`} className="font-semibold text-teal-deep hover:underline">{comment.post_title}</Link>
                                            </p>
                                            <p className="mt-2 text-xs text-muted">Submitted {formatDate(comment.created_at)}</p>
                                            <p className="mt-5 rounded-control bg-page-tint p-4 leading-7 text-body">{comment.body}</p>
                                            <div className="mt-5 flex flex-wrap gap-2">
                                                <button type="button" className="button-success" aria-label={`Approve comment by ${comment.user_name}`} onClick={() => moderateComment(comment.id, 'approved')}>Approve</button>
                                                <button type="button" className="button-danger" aria-label={`Reject comment by ${comment.user_name}`} onClick={() => moderateComment(comment.id, 'rejected')}>Reject</button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                        ) : <EmptyQueue>No comments are waiting for review.</EmptyQueue>}
                    </div>

                    <div
                        id="panel-reports"
                        role="tabpanel"
                        aria-labelledby="tab-reports"
                        hidden={activeQueue !== 'reports'}
                        className="p-5 sm:p-6"
                    >
                        {reports.length > 0 ? (
                                <ul className="space-y-4">
                                    {reports.map((report) => (
                                        <li key={report.id} className="workspace-glass-card p-6">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h3 className="font-bold text-brand-ink">{report.reportable_type} #{report.reportable_id}</h3>
                                                    <p className="mt-1 text-sm text-muted">Reported by {report.reporter ?? 'Unknown account'} · {formatDate(report.created_at)}</p>
                                                </div>
                                                <span className="badge-warning">Needs attention</span>
                                            </div>
                                            <p className="mt-5 rounded-control bg-page-tint p-4 leading-7 text-body">{report.reason}</p>
                                            <div className="mt-5 flex flex-wrap gap-2">
                                                <button type="button" className="button-primary" aria-label={`Resolve report ${report.id}`} onClick={() => router.post(`/admin/moderation/reports/${report.id}`, { status: 'resolved' })}>Resolve</button>
                                                <button type="button" className="button-secondary" aria-label={`Dismiss report ${report.id}`} onClick={() => router.post(`/admin/moderation/reports/${report.id}`, { status: 'dismissed' })}>Dismiss</button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                        ) : <EmptyQueue>No reports are open.</EmptyQueue>}
                    </div>

                    <div
                        id="panel-suspended"
                        role="tabpanel"
                        aria-labelledby="tab-suspended"
                        hidden={activeQueue !== 'suspended'}
                        className="p-5 sm:p-6"
                    >
                        {suspended.length > 0 ? (
                                <ul className="grid gap-4 xl:grid-cols-2">
                                    {suspended.map((profile) => {
                                        const name = profile.display_name ?? profile.user_name;
                                        return (
                                            <li key={profile.id} className="workspace-glass-card p-6">
                                                <h3 className="font-bold text-brand-ink">{name}</h3>
                                                <p className="mt-1 text-sm text-muted">Account: {profile.user_name}</p>
                                                {profile.notes && <p className="mt-4 rounded-control bg-page-tint p-4 text-sm leading-6">{profile.notes}</p>}
                                                <button type="button" className="button-secondary mt-5" aria-label={`Lift suspension for ${name}`} onClick={() => moderateProfile(profile.id, 'private')}>Lift suspension</button>
                                            </li>
                                        );
                                    })}
                                </ul>
                        ) : <EmptyQueue>No profiles are suspended.</EmptyQueue>}
                    </div>
                </section>
            </main>
        </WorkspaceLayout>
    );
}
