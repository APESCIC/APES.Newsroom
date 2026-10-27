import { Head } from '@inertiajs/react';
import { useState } from 'react';
import FilterBar from '../../../Components/Admin/FilterBar';
import Pagination from '../../../Components/Admin/Pagination';
import type { Paginator } from '../../../Components/Admin/types';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

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

export default function Index({
    filters,
    entries,
    subjectTypes,
}: {
    filters: AuditFilters;
    entries: Paginator<AuditEntry>;
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
                <a href={exportHref(filters)} className="button-secondary">
                    Export CSV
                </a>
            }
        >
            <Head title="Audit log" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6">
                <FilterBar action="/admin/audit-log" values={values} label="Filter audit log">
                    <label className="text-sm font-bold text-body">
                        Actor
                        <input className="form-input mt-1" value={values.actor} onChange={(e) => set('actor')(e.target.value)} placeholder="Name, email or id" />
                    </label>
                    <label className="text-sm font-bold text-body">
                        Action starts with
                        <input className="form-input mt-1" value={values.action} onChange={(e) => set('action')(e.target.value)} placeholder="auth. or webhook." />
                    </label>
                    <label className="text-sm font-bold text-body">
                        Subject type
                        <select className="form-input mt-1" value={values.subject_type} onChange={(e) => set('subject_type')(e.target.value)}>
                            <option value="">Any</option>
                            {subjectTypes.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-bold text-body">
                        Subject id
                        <input inputMode="numeric" className="form-input mt-1" value={values.subject_id} onChange={(e) => set('subject_id')(e.target.value)} />
                    </label>
                    <label className="text-sm font-bold text-body">
                        From
                        <input type="date" className="form-input mt-1" value={values.from} onChange={(e) => set('from')(e.target.value)} />
                    </label>
                    <label className="text-sm font-bold text-body">
                        To
                        <input type="date" className="form-input mt-1" value={values.to} onChange={(e) => set('to')(e.target.value)} />
                    </label>
                    <label className="text-sm font-bold text-body">
                        IP address
                        <input className="form-input mt-1" value={values.ip} onChange={(e) => set('ip')(e.target.value)} />
                    </label>
                </FilterBar>

                <p className="text-sm text-muted">
                    {entries.total} record{entries.total === 1 ? '' : 's'}
                </p>

                {entries.data.length === 0 ? (
                    <p className="workspace-glass-card p-8 text-center text-muted">No audit records match these filters.</p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {entries.data.map((entry) => (
                            <li key={entry.id} className="glass-form-panel" data-testid="audit-entry">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-mono text-sm font-bold text-body">{entry.action}</p>
                                    <p className="text-xs text-muted">{formatWhen(entry.created_at)}</p>
                                </div>
                                <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt className="text-muted">Actor</dt>
                                        <dd className="text-body">{entry.actor ? `${entry.actor.name} (${entry.actor.email})` : 'System or guest'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted">Subject</dt>
                                        <dd className="text-body">{entry.subject ? `${entry.subject.type} #${entry.subject.id ?? '—'}` : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted">IP address</dt>
                                        <dd className="text-body">{entry.ip_address ?? '—'}</dd>
                                    </div>
                                </dl>
                                {(entry.payload.length > 0 || entry.user_agent) && (
                                    <details className="mt-3 text-sm">
                                        <summary className="cursor-pointer text-muted">Details</summary>
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
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination page={entries} label="Audit log" />
            </div>
        </WorkspaceLayout>
    );
}
