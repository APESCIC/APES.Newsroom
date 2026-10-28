import { router, useForm } from '@inertiajs/react';
import { Download, Upload } from 'lucide-react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import { FormSection } from '../../../Components/Workspace/ui/FormField';

type Run = {
    id: number;
    type: 'ghost_members_csv' | 'ghost_newsletters_csv';
    status: string;
    dry_run: boolean;
    source_checksum: string | null;
    report: Record<string, unknown> | null;
    created_at: string | null;
    finished_at: string | null;
};

export default function GhostMembersImport({ runs }: { runs: Run[] }) {
    const form = useForm<{ csv: File | null; mode: 'dry-run' }>({
        csv: null,
        mode: 'dry-run',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/admin/imports/ghost-members', {
            forceFormData: true,
        });
    };

    return (
        <WorkspaceLayout area="Admin" active="members" title="Ghost members import" subtitle="Dry-run a Ghost Admin members export. No email is sent from import runs.">
            <p className="text-sm text-muted">
                Upload a Ghost Admin members export for a dry run of contacts and opt-outs. Newsletter subscriptions are reconciled from Ghost&apos;s
                per-newsletter exports with <code>php artisan ghost:reconcile-newsletters</code> (dry run first, then apply after sign-off).
            </p>

            <FormSection title="Upload export">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div>
                        <label htmlFor="csv" className="text-sm font-semibold text-body">
                            Members CSV
                        </label>
                        <input
                            id="csv"
                            type="file"
                            accept=".csv,text/csv"
                            required
                            onChange={(e) => form.setData('csv', e.target.files?.[0] ?? null)}
                            className="form-input mt-1.5"
                        />
                        {form.errors.csv && <p className="mt-1 text-sm text-danger">{form.errors.csv}</p>}
                    </div>
                    {form.errors.mode && <p className="text-sm text-danger">{form.errors.mode}</p>}
                    <button type="submit" disabled={form.processing} className="sg-btn sg-btn-primary self-start">
                        <Upload aria-hidden="true" className="h-4 w-4" />
                        Queue dry run
                    </button>
                </form>
            </FormSection>

            <section aria-label="Recent runs" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold text-body">Recent runs</h2>
                {runs.length === 0 ? (
                    <EmptyState title="No import runs yet." />
                ) : (
                    <ul className="flex flex-col gap-3 text-sm">
                        {runs.map((run) => (
                            <li key={run.id} className="sg-card p-4">
                                <p className="flex flex-wrap items-center gap-2 font-semibold text-body">
                                    #{run.id} {run.type === 'ghost_newsletters_csv' ? 'newsletter reconcile' : 'members'}
                                    <Badge tone={run.status === 'completed' ? 'ok' : run.status === 'failed' ? 'error' : 'info'}>{run.status}</Badge>
                                    <Badge tone="neutral">{run.dry_run ? 'dry-run' : 'applied'}</Badge>
                                </p>
                                <p className="mt-1 break-all text-muted">Checksum: {run.source_checksum}</p>
                                {run.report && (
                                    <pre className="mt-2 max-h-64 overflow-auto rounded-xl bg-white/70 p-3 text-xs">{JSON.stringify(run.report, null, 2)}</pre>
                                )}
                                <button
                                    type="button"
                                    className="sg-btn sg-btn-secondary sg-btn-sm mt-3"
                                    onClick={() => router.visit(`/admin/imports/ghost-members/${run.id}/report`)}
                                >
                                    <Download aria-hidden="true" className="h-4 w-4" />
                                    Download report
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </WorkspaceLayout>
    );
}
