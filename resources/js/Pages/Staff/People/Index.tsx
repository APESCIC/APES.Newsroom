import { Head, Link, router } from '@inertiajs/react';
import { Contact } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';
import { formatWhen } from '../../Admin/Users/types';

type PersonRow = {
    id: number;
    name: string;
    email: string;
    signed_up_at: string;
    membership_label: string;
    is_paying: boolean;
    is_comped: boolean;
    plan_name: string | null;
};

type Filters = { q: string; status: string };

/** Public accounts staff can view and edit (#571). Subscribers without an account are under Subscribers. */
export default function Index({ people, filters }: { people: PageMeta & { data: PersonRow[]; total: number }; filters: Filters }) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status || 'all');

    function applyFilters(event: FormEvent) {
        event.preventDefault();
        router.get('/staff/people', { q: q || undefined, status: status === 'all' ? undefined : status }, { preserveState: true, replace: true });
    }

    return (
        <WorkspaceLayout area="Staff" active="people" title="People" subtitle="Reader accounts, their membership, newsletters and public profile">
            <Head title="People" />
            <p className="text-sm text-muted">
                Open a person to view or edit their details. Mailing-list subscribers without an account are listed under{' '}
                <Link href="/staff/subscribers" className="text-body underline">
                    Subscribers
                </Link>
                .
            </p>

            <form role="search" aria-label="Filter people" className="sg-card grid gap-3 p-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end" onSubmit={applyFilters}>
                <label className="text-sm font-semibold text-body">
                    Search
                    <input type="search" className="form-input mt-1.5" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or email" />
                </label>
                <label className="text-sm font-semibold text-body">
                    Membership
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
                {people.total} {people.total === 1 ? 'person' : 'people'}
            </p>

            {people.data.length === 0 ? (
                <EmptyState icon={Contact} title="No people found" description="Try a different name, email or membership filter." />
            ) : (
                <DataTable
                    label="People"
                    rows={people.data}
                    rowKey={(person) => person.id}
                    columns={[
                        {
                            key: 'person',
                            header: 'Person',
                            primary: true,
                            cell: (person) => (
                                <>
                                    <Link href={`/staff/people/users/${person.id}`} className="font-semibold text-body underline-offset-2 hover:underline">
                                        {person.name}
                                    </Link>
                                    <p className="text-xs text-muted">{person.email}</p>
                                </>
                            ),
                        },
                        {
                            key: 'membership',
                            header: 'Membership',
                            cell: (person) => <Badge tone={person.is_paying || person.is_comped ? 'ok' : 'neutral'}>{person.membership_label}</Badge>,
                        },
                        { key: 'plan', header: 'Plan', cell: (person) => person.plan_name ?? '—' },
                        { key: 'joined', header: 'Joined', cell: (person) => <span className="text-muted">{formatWhen(person.signed_up_at)}</span> },
                    ]}
                />
            )}

            <Pagination page={people} label="People" />
        </WorkspaceLayout>
    );
}
