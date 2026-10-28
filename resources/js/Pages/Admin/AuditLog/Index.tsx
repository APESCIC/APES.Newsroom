import { Download, ScrollText } from 'lucide-react';
import { useState } from 'react';
import FilterBar from '../../../Components/Admin/FilterBar';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';

export type AuditFilters = {
    actor: string;
    action: string;
    subject_type: string;
    subject_id: string;
    from: string;
    to: string;
    ip: string;
};

export type AuditEntry = {
    id: number;
    created_at: string | null;
    action: string;
    actor: { id: number; name: string; email: string } | null;
    subject: { type: string; id: number | null } | null;
    ip_address: string | null;
    user_agent: string | null;
    payload: { key: string; value: string }[];
};

function formatWhen(iso: string | null) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'medium', timeZone: 'Europe/London' }).format(new Date(iso));
}

function exportHref(filters: AuditFilters) {
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''));
    const suffix = query.toString();
    return `/admin/audit-log/export${suffix ? `?${suffix}` : ''}`;
}

function EntryDetails({ entry }: { entry: AuditEntry }) {
    if (entry.payload.length === 0 && !entry.user_agent) return <span className="text-muted">—</span>;

    return (
        <details className="text-sm">
            <summary className="cursor-pointer font-medium text-teal-deep">Details</summary>
            <dl className="mt-2 grid gap-1">
                {entry.user_agent && (
                    <div className="flex flex-wrap gap-2">
                        <dt className="font-mono text-muted">user_agent</dt>
                        <dd className="break-all text-body">{entry.user_agent}</dd>
                    </div>
                )}
                {entry.payload.map((pair) => (
                    <div key={pair.key} className="flex flex-wrap gap-2">
                        <dt className="font-mono text-muted">{pair.key}</dt>
                        <dd className="break-all text-body">{pair.value}</dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

export default function Index({
    filters,
    entries,
    subjectTypes,
}: {
    filters: AuditFilters;
    entries: PageMeta & { data: AuditEntry[]; total: number };
    subjectTypes: string[];
}) {
    const [values, setValues] = useState<AuditFilters>(filters);
    const set = (key: keyof AuditFilters) => (value: string) => setValues((current) => ({ ...current, [key]: value }));

    return (
        <WorkspaceLayout
            area="Admin"
            active="audit-log"
            title="Audit log"
            subtitle="Privileged and security events, newest first"
            actions={
                <a href={exportHref(filters)} className="sg-btn sg-btn-secondary">
                    <Download aria-hidden="true" className="h-4 w-4" />
                    Export CSV
                </a>
            }
        >
            <FilterBar action="/admin/audit-log" values={values} label="Filter audit log">
                <label className="text-sm font-semibold text-body">
                    Actor
                    <input className="form-input mt-1.5" value={values.actor} onChange={(e) => set('actor')(e.target.value)} placeholder="Name, email or id" />
                </label>
                <label className="text-sm font-semibold text-body">
                    Action starts with
                    <input className="form-input mt-1.5" value={values.action} onChange={(e) => set('action')(e.target.value)} placeholder="auth. or webhook." />
                </label>
                <label className="text-sm font-semibold text-body">
                    Subject type
                    <select className="form-input mt-1.5" value={values.subject_type} onChange={(e) => set('subject_type')(e.target.value)}>
                        <option value="">Any</option>
                        {subjectTypes.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-sm font-semibold text-body">
                    Subject id
                    <input inputMode="numeric" className="form-input mt-1.5" value={values.subject_id} onChange={(e) => set('subject_id')(e.target.value)} />
                </label>
                <label className="text-sm font-semibold text-body">
                    From
                    <input type="date" className="form-input mt-1.5" value={values.from} onChange={(e) => set('from')(e.target.value)} />
                </label>
                <label className="text-sm font-semibold text-body">
                    To
                    <input type="date" className="form-input mt-1.5" value={values.to} onChange={(e) => set('to')(e.target.value)} />
                </label>
                <label className="text-sm font-semibold text-body">
                    IP address
                    <input className="form-input mt-1.5" value={values.ip} onChange={(e) => set('ip')(e.target.value)} />
                </label>
            </FilterBar>

            <p className="text-sm text-muted">
                {entries.total} record{entries.total === 1 ? '' : 's'}
            </p>

            {entries.data.length === 0 ? (
                <EmptyState icon={ScrollText} title="No audit records match these filters." />
            ) : (
                <DataTable
                    label="Audit records"
                    rows={entries.data}
                    rowKey={(entry) => entry.id}
                    rowTestId="audit-entry"
                    columns={[
                        {
                            key: 'action',
                            header: 'Action',
                            primary: true,
                            cell: (entry) => (
                                <>
                                    <p className="font-mono font-semibold text-body">{entry.action}</p>
                                    <p className="text-xs text-muted">{formatWhen(entry.created_at)}</p>
                                </>
                            ),
                        },
                        { key: 'actor', header: 'Actor', cell: (entry) => (entry.actor ? `${entry.actor.name} (${entry.actor.email})` : 'System or guest') },
                        { key: 'subject', header: 'Subject', cell: (entry) => (entry.subject ? `${entry.subject.type} #${entry.subject.id ?? '—'}` : '—') },
                        { key: 'ip', header: 'IP address', cell: (entry) => entry.ip_address ?? '—' },
                        { key: 'details', header: 'Details', cell: (entry) => <EntryDetails entry={entry} /> },
                    ]}
                />
            )}

            <Pagination page={entries} label="Audit log" />
        </WorkspaceLayout>
    );
}
