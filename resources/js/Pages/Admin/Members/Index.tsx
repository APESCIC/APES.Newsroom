import { Head, Link, router } from '@inertiajs/react';
import { IdCard } from 'lucide-react';
import { FormEvent, useState } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';

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
    is_comped: boolean;
    plan_name: string | null;
    plan_interval: string | null;
    recent_reads: RecentRead[];
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

function RecentReads({ reads }: { reads: RecentRead[] }) {
    if (reads.length === 0) {
        return <span className="text-muted">No signed-in views recorded yet.</span>;
    }

    return (
        <details>
            <summary className="cursor-pointer font-medium text-teal-deep">
                {reads.length} recent read{reads.length === 1 ? '' : 's'}
            </summary>
            <ul className="mt-2 flex flex-col gap-1">
                {reads.map((read) => (
                    <li key={`${read.path}-${read.viewed_at}`} className="flex flex-wrap justify-between gap-2">
                        <span className="break-all">{read.path}</span>
                        <span className="text-muted">{formatWhen(read.viewed_at)}</span>
                    </li>
                ))}
            </ul>
        </details>
    );
}

export default function Index({ members, filters }: { members: PageMeta & { data: MemberRow[]; total: number }; filters: Filters }) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status || 'all');

    function applyFilters(event: FormEvent) {
        event.preventDefault();
        router.get('/admin/members', { q: q || undefined, status: status === 'all' ? undefined : status }, { preserveState: true, replace: true });
    }

    return (
        <WorkspaceLayout area="Admin" active="members" title="Members" subtitle="Public members, their plan and recent signed-in reading">
            <Head title="Members" />
            <p className="text-sm text-muted">
                Search public members by name or email. Filter by free, paying or complimentary status and review recent signed-in reading activity.
                Open a member to edit their details, membership and newsletters. To change account security or delete an account, use{' '}
                <Link href="/admin/users" className="text-body underline">
                    Users
                </Link>
                .
            </p>

            <form role="search" aria-label="Filter members" className="sg-card grid gap-3 p-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end" onSubmit={applyFilters}>
                <label className="text-sm font-semibold text-body">
                    Search
                    <input className="form-input mt-1.5" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or email" />
                </label>
                <label className="text-sm font-semibold text-body">
                    Status
                    <select className="form-input mt-1.5" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="all">All</option>
                        <option value="free">Free</option>
                        <option value="paying">Paying</option>
                        <option value="comped">Complimentary</option>
                    </select>
                </label>
                <button type="submit" className="sg-btn sg-btn-primary">
                    Apply
                </button>
            </form>

            <p className="text-sm text-muted">
                {members.total} member{members.total === 1 ? '' : 's'}
            </p>

            {members.data.length === 0 ? (
                <EmptyState icon={IdCard} title="No members found" description="Try a different name, email or status." />
            ) : (
                <DataTable
                    label="Members"
                    rows={members.data}
                    rowKey={(member) => member.id}
                    columns={[
                        {
                            key: 'member',
                            header: 'Member',
                            primary: true,
                            cell: (member) => (
                                <>
                                    <Link href={`/staff/people/users/${member.id}`} className="font-semibold text-body underline-offset-2 hover:underline">
                                        {member.name}
                                    </Link>
                                    <p className="text-xs text-muted">{member.email}</p>
                                </>
                            ),
                        },
                        {
                            key: 'membership',
                            header: 'Membership',
                            cell: (member) => <Badge tone={member.is_paying || member.is_comped ? 'ok' : 'neutral'}>{member.membership_label}</Badge>,
                        },
                        {
                            key: 'plan',
                            header: 'Plan',
                            cell: (member) => (member.plan_name ? `${member.plan_name}${member.plan_interval ? ` (${member.plan_interval})` : ''}` : '—'),
                        },
                        { key: 'signed-up', header: 'Signed up', cell: (member) => <span className="text-muted">{formatWhen(member.signed_up_at)}</span> },
                        { key: 'reads', header: 'Recent reads', cell: (member) => <RecentReads reads={member.recent_reads} /> },
                    ]}
                />
            )}

            <Pagination page={members} label="Members" />
        </WorkspaceLayout>
    );
}
