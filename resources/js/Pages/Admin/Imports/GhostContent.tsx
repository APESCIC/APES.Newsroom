import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

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
    const { flash, errors } = usePage().props as {
        flash?: { status?: string };
        errors?: Record<string, string>;
    };
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

    const confirm = (runId: number) => {
        router.post(`/admin/imports/ghost-content/${runId}/confirm`);
    };

    return (
        <>
            <Head title="Ghost content import" />
            <main className="mx-auto max-w-3xl px-6 py-12">
                <h1 className="text-2xl font-semibold">Ghost content JSON import</h1>
                <p className="mt-2 text-sm text-muted">
                    Upload a Ghost Admin content export. A dry-run runs first and shows the report. Confirm separately to
                    persist. Re-running the same export does not duplicate posts, tags, authors, or redirects. No email is
                    sent from import runs.
                </p>
                {flash?.status && <p className="mt-4 text-sm text-green-700">{flash.status}</p>}
                {errors?.confirm && <p className="mt-4 text-sm text-red-600">{errors.confirm}</p>}

                <form onSubmit={submit} className="mt-8 flex flex-col gap-4 rounded border p-4">
                    <div>
                        <label htmlFor="json" className="text-sm font-medium">
                            Ghost content JSON
                        </label>
                        <input
                            id="json"
                            type="file"
                            accept=".json,application/json"
                            required
                            onChange={(e) => form.setData('json', e.target.files?.[0] ?? null)}
                            className="mt-1 block w-full text-sm"
                        />
                        {form.errors.json && <p className="text-sm text-red-600">{form.errors.json}</p>}
                    </div>
                    <div>
                        <label htmlFor="media" className="text-sm font-medium">
                            Optional media archive (.zip)
                        </label>
                        <input
                            id="media"
                            type="file"
                            accept=".zip,application/zip"
                            onChange={(e) => form.setData('media', e.target.files?.[0] ?? null)}
                            className="mt-1 block w-full text-sm"
                        />
                        {form.errors.media && <p className="text-sm text-red-600">{form.errors.media}</p>}
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-fit rounded bg-apes-primary px-4 py-2 text-white"
                    >
                        Upload and dry-run
                    </button>
                </form>

                <section className="mt-10">
                    <h2 className="text-lg font-medium">Recent runs</h2>
                    <ul className="mt-4 space-y-3 text-sm">
                        {runs.map((run) => (
                            <li key={run.id} className="rounded border p-3">
                                <p>
                                    #{run.id} — {run.status} — {run.dry_run ? 'dry-run' : 'import'}
                                </p>
                                <p className="text-muted">Checksum: {run.source_checksum}</p>
                                {run.report && (
                                    <pre className="mt-2 overflow-auto rounded-control bg-page-tint p-2 text-xs">
                                        {JSON.stringify(run.report, null, 2)}
                                    </pre>
                                )}
                                <div className="mt-2 flex flex-wrap gap-3">
                                    {run.dry_run && run.status === 'completed' && run.source_available && (
                                        <button
                                            type="button"
                                            className="rounded bg-apes-primary px-3 py-1 text-white"
                                            onClick={() => confirm(run.id)}
                                        >
                                            Confirm import
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className="underline"
                                        onClick={() => router.visit(`/admin/imports/ghost-content/${run.id}/report`)}
                                    >
                                        Download report
                                    </button>
                                </div>
                            </li>
                        ))}
                        {runs.length === 0 && <li className="text-muted">No import runs yet.</li>}
                    </ul>
                </section>
            </main>
        </>
    );
}
