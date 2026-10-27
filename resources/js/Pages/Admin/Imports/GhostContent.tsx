import { router, useForm, usePage } from '@inertiajs/react';
import { Download, Upload } from 'lucide-react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import { FormSection } from '../../../Components/Workspace/ui/FormField';

type Run = {
    id: number;
    status: string;
    dry_run: boolean;
    source_checksum: string | null;
    source_available: boolean;
    report: Record<string, unknown> | null;
    created_at: string | null;
    finished_at: string | null;
};

export default function GhostContentImport({ runs }: { runs: Run[] }) {
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const form = useForm<{ json: File | null; media: File | null }>({
        json: null,
        media: null,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/admin/imports/ghost-content', {
            forceFormData: true,
        });
    };

    const confirmRun = (runId: number) => {
        router.post(`/admin/imports/ghost-content/${runId}/confirm`);
    };

    return (
        <WorkspaceLayout
            area="Staff"
            active="posts"
            title="Ghost content import"
            subtitle="Upload a Ghost Admin content export. A dry run comes first; confirm separately to persist. Re-running the same export does not duplicate posts, tags, authors or redirects. No email is sent."
        >
            {errors?.confirm && (
                <p role="alert" className="sg-card border-l-4 !border-l-[var(--color-sg-error)] px-4 py-3 text-sm text-danger">
                    {errors.confirm}
                </p>
            )}

            <FormSection title="Upload export">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label htmlFor="json" className="text-sm font-semibold text-body">
                                Ghost content JSON
                            </label>
                            <input
                                id="json"
                                type="file"
                                accept=".json,application/json"
                                required
                                onChange={(e) => form.setData('json', e.target.files?.[0] ?? null)}
                                className="form-input mt-1.5"
                            />
                            {form.errors.json && <p className="mt-1 text-sm text-danger">{form.errors.json}</p>}
                        </div>
                        <div>
                            <label htmlFor="media" className="text-sm font-semibold text-body">
                                Optional media archive (.zip)
                            </label>
                            <input
                                id="media"
                                type="file"
                                accept=".zip,application/zip"
                                onChange={(e) => form.setData('media', e.target.files?.[0] ?? null)}
                                className="form-input mt-1.5"
                            />
                            {form.errors.media && <p className="mt-1 text-sm text-danger">{form.errors.media}</p>}
                        </div>
                    </div>
                    <button type="submit" disabled={form.processing} className="sg-btn sg-btn-primary self-start">
                        <Upload aria-hidden="true" className="h-4 w-4" />
                        Upload and dry-run
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
                                    #{run.id}
                                    <Badge tone={run.status === 'completed' ? 'ok' : run.status === 'failed' ? 'error' : 'info'}>{run.status}</Badge>
                                    <Badge tone="neutral">{run.dry_run ? 'dry-run' : 'import'}</Badge>
                                </p>
                                <p className="mt-1 break-all text-muted">Checksum: {run.source_checksum}</p>
                                {run.report && (
                                    <pre className="mt-2 max-h-64 overflow-auto rounded-xl bg-white/70 p-3 text-xs">{JSON.stringify(run.report, null, 2)}</pre>
                                )}
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {run.dry_run && run.status === 'completed' && run.source_available && (
                                        <button type="button" className="sg-btn sg-btn-primary sg-btn-sm" onClick={() => confirmRun(run.id)}>
                                            Confirm import
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className="sg-btn sg-btn-secondary sg-btn-sm"
                                        onClick={() => router.visit(`/admin/imports/ghost-content/${run.id}/report`)}
                                    >
                                        <Download aria-hidden="true" className="h-4 w-4" />
                                        Download report
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </WorkspaceLayout>
    );
}
