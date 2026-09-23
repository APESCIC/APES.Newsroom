import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type RecentRead = {
    path: string;
    viewed_at: string;
    post_id: number | null;
    page_id: number | null;
};

type MemberRow = {
    id: number;
    name: string;
    email: string;
    signed_up_at: string;
    membership_status: string;
    membership_label: string;
    is_paying: boolean;
    plan_name: string | null;
    plan_interval: string | null;
    recent_reads: RecentRead[];
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Filters = { q: string; status: string };

function formatWhen(iso: string): string {
    if (!iso) {
        return '—';
    }
    try {
        return new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Europe/London',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

export default function Index({ members, filters }: { members: Paginator<MemberRow>; filters: Filters }) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status || 'all');

    function applyFilters(event: FormEvent) {
        event.preventDefault();
        router.get(
            '/staff/members',
            { q: q || undefined, status: status === 'all' ? undefined : status },
            { preserveState: true, replace: true },
        );
    }

    return (
        <WorkspaceLayout area="Staff" active="members" title="Members">
            <Head title="Members" />
            <div className="mx-auto max-w-4xl flex flex-col gap-6">
                <p className="text-sm text-muted">
                    Search public members by name or email. Filter by free or paying status and review recent signed-in
                    reading activity.
                </p>

                <form className="glass-form-panel flex flex-col gap-3 sm:flex-row sm:items-end" onSubmit={applyFilters}>
                    <label className="flex-1 text-sm font-bold text-body">
                        Search
                        <input
                            className="form-input mt-1"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Name or email"
                        />
                    </label>
                    <label className="text-sm font-bold text-body sm:w-40">
                        Status
                        <select className="form-input mt-1" value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="all">All</option>
                            <option value="free">Free</option>
                            <option value="paying">Paying</option>
                        </select>
                    </label>
                    <button type="submit" className="button-primary w-fit">
                        Apply
                    </button>
                </form>

                <p className="text-sm text-muted">{members.total} member{members.total === 1 ? '' : 's'}</p>

                <ul className="flex flex-col gap-4">
                    {members.data.map((member) => (
                        <li key={member.id} className="glass-form-panel">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <div>
                                    <h2 className="text-lg font-bold text-body">{member.name}</h2>
                                    <p className="text-sm text-muted">{member.email}</p>
                                </div>
                                <p className="text-sm font-bold text-body">{member.membership_label}</p>
                            </div>
                            <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted">Signed up</dt>
                                    <dd className="text-body">{formatWhen(member.signed_up_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted">Plan</dt>
                                    <dd className="text-body">
                                        {member.plan_name
                                            ? `${member.plan_name}${member.plan_interval ? ` (${member.plan_interval})` : ''}`
                                            : '—'}
                                    </dd>
                                </div>
                            </dl>
                            <div className="mt-4">
                                <h3 className="text-sm font-bold text-body">Recent reads</h3>
                                {member.recent_reads.length === 0 ? (
                                    <p className="mt-1 text-sm text-muted">No signed-in views recorded yet.</p>
                                ) : (
                                    <ul className="mt-2 flex flex-col gap-1 text-sm text-body">
                                        {member.recent_reads.map((read) => (
                                            <li key={`${read.path}-${read.viewed_at}`} className="flex flex-wrap justify-between gap-2">
                                                <span>{read.path}</span>
                                                <span className="text-muted">{formatWhen(read.viewed_at)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>

                {(members.prev_page_url || members.next_page_url) && (
                    <div className="flex gap-3">
                        {members.prev_page_url && (
                            <button type="button" className="button-secondary" onClick={() => router.get(members.prev_page_url!)}>
                                Previous
                            </button>
                        )}
                        {members.next_page_url && (
                            <button type="button" className="button-secondary" onClick={() => router.get(members.next_page_url!)}>
                                Next
                            </button>
                        )}
                    </div>
                )}
            </div>
        </WorkspaceLayout>
    );
}
