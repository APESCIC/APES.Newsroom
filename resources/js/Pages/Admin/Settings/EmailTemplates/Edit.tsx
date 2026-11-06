import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Monitor, Smartphone } from 'lucide-react';
import { useId, useMemo, useRef, useState, type FormEvent, type RefObject } from 'react';
import { refusalMessage, xsrfToken } from '../../../../Components/Admin/EmailDomains/TestSendPanel';
import WorkspaceLayout from '../../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../../Components/Workspace/ui/Badge';
import ConfirmDialog from '../../../../Components/Workspace/ui/ConfirmDialog';
import PillButton from '../../../../Components/Workspace/ui/PillButton';
import type { SharedPageProps } from '../../../../types/page';

type Definition = {
    key: string;
    name: string;
    group: string;
    is_security: boolean;
    allowed_placeholders: string[];
    required_placeholders: string[];
    default_subject: string;
    default_body: string;
    locked_notice: string;
    action_label: string | null;
    sample_placeholders: Record<string, string>;
};

type Version = {
    id: number;
    subject: string;
    body: string;
    editor: string | null;
    created_at: string | null;
};

type PreviewPayload = {
    subject: string;
    html: string;
    text: string;
};

const TAG_PATTERN = /\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/gu;

function extractTags(value: string): string[] {
    const found = new Set<string>();
    for (const match of value.matchAll(TAG_PATTERN)) {
        found.add(match[1]);
    }
    return [...found];
}

function missingRequired(subject: string, body: string, required: string[]): string[] {
    const present = new Set([...extractTags(subject), ...extractTags(body)]);
    return required.filter((tag) => !present.has(tag));
}

function insertAtCursor(field: HTMLInputElement | HTMLTextAreaElement, tag: string, current: string, setValue: (next: string) => void): void {
    const start = field.selectionStart ?? current.length;
    const end = field.selectionEnd ?? current.length;
    const insertion = `{{ ${tag} }}`;
    const next = `${current.slice(0, start)}${insertion}${current.slice(end)}`;
    setValue(next);
    requestAnimationFrame(() => {
        field.focus();
        const caret = start + insertion.length;
        field.setSelectionRange(caret, caret);
    });
}

function formatTime(iso: string | null): string {
    if (!iso) return 'Unknown time';
    return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

function UnifiedDiff({ label, before, after }: { label: string; before: string; after: string }) {
    if (before === after) {
        return (
            <p className="text-xs text-muted">
                {label}: unchanged
            </p>
        );
    }

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <div>
                <p className="mb-1 text-xs font-semibold text-muted">{label} (version)</p>
                <pre className="max-h-40 overflow-auto whitespace-pre-wrap rounded border border-border bg-surface p-2 text-xs text-body">{before}</pre>
            </div>
            <div>
                <p className="mb-1 text-xs font-semibold text-muted">{label} (current draft)</p>
                <pre className="max-h-40 overflow-auto whitespace-pre-wrap rounded border border-border bg-surface p-2 text-xs text-body">{after}</pre>
            </div>
        </div>
    );
}

export default function EmailTemplateEdit({
    definition,
    subject: initialSubject,
    body: initialBody,
    is_overridden: isOverridden,
    versions,
    testRecipient,
    groupLabels,
}: {
    definition: Definition;
    subject: string;
    body: string;
    is_overridden: boolean;
    updated_at: string | null;
    versions: Version[];
    testRecipient: string;
    groupLabels: Record<string, string>;
}) {
    const { flash, errors } = usePage<SharedPageProps>().props;
    const form = useForm({ subject: initialSubject, body: initialBody });
    const subjectRef = useRef<HTMLInputElement>(null);
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const subjectId = useId();
    const bodyId = useId();
    const [activeField, setActiveField] = useState<'subject' | 'body'>('body');
    const [previewWidth, setPreviewWidth] = useState<'desktop' | 'mobile'>('desktop');
    const [preview, setPreview] = useState<PreviewPayload | null>(null);
    const [previewBusy, setPreviewBusy] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [testBusy, setTestBusy] = useState(false);
    const [testError, setTestError] = useState<string | null>(null);
    const [testMessage, setTestMessage] = useState<string | null>(null);
    const [revertOpen, setRevertOpen] = useState(false);
    const [restoreId, setRestoreId] = useState<number | null>(null);
    const [expandedVersion, setExpandedVersion] = useState<number | null>(null);

    const clientMissing = useMemo(
        () => missingRequired(form.data.subject, form.data.body, definition.required_placeholders),
        [form.data.subject, form.data.body, definition.required_placeholders],
    );
    const unknownTags = useMemo(() => {
        const allowed = new Set(definition.allowed_placeholders);
        return [...extractTags(form.data.subject), ...extractTags(form.data.body)].filter((tag) => !allowed.has(tag));
    }, [form.data.subject, form.data.body, definition.allowed_placeholders]);

    const canSave = clientMissing.length === 0 && unknownTags.length === 0 && !form.processing;

    const insertPlaceholder = (tag: string) => {
        if (activeField === 'subject' && subjectRef.current) {
            insertAtCursor(subjectRef.current, tag, form.data.subject, (next) => form.setData('subject', next));
            return;
        }
        if (bodyRef.current) {
            insertAtCursor(bodyRef.current, tag, form.data.body, (next) => form.setData('body', next));
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!canSave) return;
        form.put(`/admin/settings/email-templates/${definition.key}`, { preserveScroll: true });
    };

    const runPreview = async () => {
        setPreviewBusy(true);
        setPreviewError(null);
        try {
            const response = await fetch(`/admin/settings/email-templates/${definition.key}/preview`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ subject: form.data.subject, body: form.data.body }),
            });
            const payload: unknown = await response.json().catch(() => null);
            if (!response.ok) {
                setPreviewError(refusalMessage(response.status, payload));
                return;
            }
            const previewPayload = payload as Partial<PreviewPayload>;
            if (typeof previewPayload.html !== 'string' || typeof previewPayload.text !== 'string' || typeof previewPayload.subject !== 'string') {
                setPreviewError('The preview response could not be read.');
                return;
            }
            setPreview({ subject: previewPayload.subject, html: previewPayload.html, text: previewPayload.text });
        } catch {
            setPreviewError('The preview could not be generated. Check your connection and try again.');
        } finally {
            setPreviewBusy(false);
        }
    };

    const runTest = async () => {
        setTestBusy(true);
        setTestError(null);
        setTestMessage(null);
        try {
            const response = await fetch(`/admin/settings/email-templates/${definition.key}/test`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ subject: form.data.subject, body: form.data.body }),
            });
            const payload: unknown = await response.json().catch(() => null);
            if (!response.ok) {
                setTestError(refusalMessage(response.status, payload));
                if (response.status === 423) router.visit('/account/confirm-identity');
                const body = (typeof payload === 'object' && payload !== null ? payload : {}) as { error?: unknown };
                if (typeof body.error === 'string' && body.error !== '') {
                    setTestError(`${refusalMessage(response.status, payload)} ${body.error}`);
                }
                return;
            }
            const okBody = (typeof payload === 'object' && payload !== null ? payload : {}) as { message?: unknown };
            setTestMessage(typeof okBody.message === 'string' ? okBody.message : 'Test email sent.');
        } catch {
            setTestError('The test email could not be sent. Check your connection and try again.');
        } finally {
            setTestBusy(false);
        }
    };

    const restoreVersion = () => {
        if (restoreId === null) return;
        router.post(`/admin/settings/email-templates/${definition.key}/versions/${restoreId}/restore`, {}, { preserveScroll: true, onFinish: () => setRestoreId(null) });
    };

    const revertToDefault = () => {
        router.post(`/admin/settings/email-templates/${definition.key}/revert`, {}, { preserveScroll: true, onFinish: () => setRevertOpen(false) });
    };

    const focusField = (field: 'subject' | 'body', ref: RefObject<HTMLInputElement | HTMLTextAreaElement | null>) => {
        setActiveField(field);
        ref.current?.focus();
    };

    return (
        <WorkspaceLayout
            area="Admin"
            active="email-templates"
            title={definition.name}
            subtitle={`${groupLabels[definition.group] ?? definition.group} · ${definition.key}`}
            actions={
                <PillButton href="/admin/settings/email-templates" variant="secondary" icon={ArrowLeft}>
                    All templates
                </PillButton>
            }
        >
            <>
                {flash?.status && (
                    <p role="status" className="sg-card border-l-4 !border-l-[var(--color-sg-success)] px-4 py-3 text-sm text-body">
                        {flash.status}
                    </p>
                )}

                <section className="sg-card flex flex-col gap-3 p-5 text-sm text-body sm:p-6" aria-labelledby="email-template-meta-heading">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id="email-template-meta-heading" className="text-base font-semibold">
                            Template
                        </h2>
                        {isOverridden ? <Badge tone="info">Overridden</Badge> : <Badge tone="neutral">Default</Badge>}
                        {definition.is_security && <Badge tone="warning">Security</Badge>}
                    </div>
                    {definition.locked_notice && <p className="text-muted">{definition.locked_notice}</p>}
                    {definition.is_security && (
                        <p className="text-muted">
                            Saving, testing, restoring or reverting this security template needs a recent sign-in confirmation (within 5 minutes).
                        </p>
                    )}
                    {definition.action_label && (
                        <p className="text-muted">
                            Action button label (fixed): <strong>{definition.action_label}</strong>
                        </p>
                    )}
                </section>

                <form onSubmit={submit} className="sg-card flex flex-col gap-4 p-5 sm:p-6" aria-labelledby="email-template-editor-heading">
                    <h2 id="email-template-editor-heading" className="text-base font-semibold text-body">
                        Subject and body
                    </h2>

                    {definition.allowed_placeholders.length > 0 && (
                        <div>
                            <p className="mb-2 text-sm font-semibold text-body">Insert placeholder</p>
                            <div className="flex flex-wrap gap-2" role="group" aria-label="Allowed placeholders">
                                {definition.allowed_placeholders.map((tag) => (
                                    <button
                                        key={tag}
                                        type="button"
                                        className="sg-btn sg-btn-secondary sg-btn-sm font-mono"
                                        onClick={() => insertPlaceholder(tag)}
                                    >
                                        {`{{ ${tag} }}`}
                                    </button>
                                ))}
                            </div>
                            <p className="mt-1 text-xs text-muted">
                                Inserts into the {activeField === 'subject' ? 'subject' : 'body'}. Required:{' '}
                                {definition.required_placeholders.length > 0
                                    ? definition.required_placeholders.map((tag) => `{{ ${tag} }}`).join(', ')
                                    : 'none'}
                                .
                            </p>
                        </div>
                    )}

                    <label htmlFor={subjectId} className="flex flex-col gap-1 text-sm font-semibold text-body">
                        Subject
                        <input
                            ref={subjectRef}
                            id={subjectId}
                            type="text"
                            className="form-input font-normal"
                            value={form.data.subject}
                            onChange={(event) => form.setData('subject', event.target.value)}
                            onFocus={() => focusField('subject', subjectRef)}
                            required
                            maxLength={255}
                            autoComplete="off"
                        />
                    </label>
                    {(form.errors.subject || errors?.subject) && (
                        <p role="alert" className="text-sm text-danger">
                            {form.errors.subject || errors?.subject}
                        </p>
                    )}

                    <label htmlFor={bodyId} className="flex flex-col gap-1 text-sm font-semibold text-body">
                        Body (Markdown)
                        <textarea
                            ref={bodyRef}
                            id={bodyId}
                            className="form-input min-h-48 font-normal font-mono text-sm"
                            value={form.data.body}
                            onChange={(event) => form.setData('body', event.target.value)}
                            onFocus={() => focusField('body', bodyRef)}
                            required
                            rows={14}
                        />
                    </label>
                    {(form.errors.body || errors?.body) && (
                        <p role="alert" className="text-sm text-danger">
                            {form.errors.body || errors?.body}
                        </p>
                    )}
                    {errors?.template && (
                        <p role="alert" className="text-sm text-danger">
                            {errors.template}
                        </p>
                    )}
                    {clientMissing.length > 0 && (
                        <p role="alert" className="text-sm text-danger">
                            Add required placeholder{clientMissing.length === 1 ? '' : 's'}:{' '}
                            {clientMissing.map((tag) => `{{ ${tag} }}`).join(', ')}.
                        </p>
                    )}
                    {unknownTags.length > 0 && (
                        <p role="alert" className="text-sm text-danger">
                            Unknown placeholder{unknownTags.length === 1 ? '' : 's'}: {unknownTags.map((tag) => `{{ ${tag} }}`).join(', ')}.
                        </p>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className="sg-btn sg-btn-primary" disabled={!canSave || !form.isDirty}>
                            {form.processing ? 'Saving…' : 'Save template'}
                        </button>
                        {isOverridden && (
                            <button type="button" className="sg-btn sg-btn-danger" onClick={() => setRevertOpen(true)}>
                                Revert to default
                            </button>
                        )}
                    </div>
                </form>

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="email-template-preview-heading">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="email-template-preview-heading" className="text-base font-semibold text-body">
                            Preview
                        </h2>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={`sg-btn sg-btn-sm ${previewWidth === 'desktop' ? 'sg-btn-primary' : 'sg-btn-secondary'}`}
                                aria-pressed={previewWidth === 'desktop'}
                                onClick={() => setPreviewWidth('desktop')}
                            >
                                <Monitor aria-hidden="true" className="mr-1 inline h-4 w-4" />
                                Desktop
                            </button>
                            <button
                                type="button"
                                className={`sg-btn sg-btn-sm ${previewWidth === 'mobile' ? 'sg-btn-primary' : 'sg-btn-secondary'}`}
                                aria-pressed={previewWidth === 'mobile'}
                                onClick={() => setPreviewWidth('mobile')}
                            >
                                <Smartphone aria-hidden="true" className="mr-1 inline h-4 w-4" />
                                Mobile
                            </button>
                            <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={previewBusy} onClick={() => void runPreview()}>
                                {previewBusy ? 'Rendering…' : 'Refresh preview'}
                            </button>
                        </div>
                    </div>
                    <p className="text-sm text-muted">Uses sample placeholder values. HTML and plain text both update from the draft above.</p>
                    {previewError && (
                        <p role="alert" className="text-sm text-danger">
                            {previewError}
                        </p>
                    )}
                    {preview ? (
                        <div className="flex flex-col gap-3">
                            <p className="text-sm text-body">
                                <span className="font-semibold">Subject:</span> {preview.subject}
                            </p>
                            <div className={`mx-auto w-full overflow-hidden rounded border border-border bg-white ${previewWidth === 'mobile' ? 'max-w-sm' : 'max-w-3xl'}`}>
                                <iframe title="HTML email preview" sandbox="" srcDoc={preview.html} className="h-[28rem] w-full border-0" />
                            </div>
                            <div>
                                <h3 className="mb-1 text-sm font-semibold text-body">Plain text</h3>
                                <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded border border-border bg-surface p-3 text-sm text-body">{preview.text}</pre>
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-muted">Click “Refresh preview” to render the current draft.</p>
                    )}
                </section>

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="email-template-test-heading">
                    <h2 id="email-template-test-heading" className="text-base font-semibold text-body">
                        Send a test
                    </h2>
                    <p className="text-sm text-muted">
                        Sends one email with the current draft to you ({testRecipient}). Test sends are throttled.
                    </p>
                    <button type="button" className="sg-btn sg-btn-primary self-start" disabled={testBusy} onClick={() => void runTest()}>
                        {testBusy ? 'Sending…' : 'Send test email'}
                    </button>
                    {testError && (
                        <p role="alert" className="text-sm text-danger">
                            {testError}
                        </p>
                    )}
                    <div aria-live="polite" aria-atomic="true">
                        {testMessage && <p className="text-sm font-semibold text-success">{testMessage}</p>}
                    </div>
                </section>

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="email-template-versions-heading">
                    <h2 id="email-template-versions-heading" className="text-base font-semibold text-body">
                        Version history
                    </h2>
                    {versions.length === 0 ? (
                        <p className="text-sm text-muted">No saved versions yet. Saving an override creates the first one.</p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {versions.map((version) => {
                                const open = expandedVersion === version.id;
                                return (
                                    <li key={version.id} className="rounded-xl border border-border p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <p className="text-sm font-semibold text-body">{formatTime(version.created_at)}</p>
                                                <p className="text-xs text-muted">Edited by {version.editor ?? 'Unknown'}</p>
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    className="sg-btn sg-btn-secondary sg-btn-sm"
                                                    aria-expanded={open}
                                                    onClick={() => setExpandedVersion(open ? null : version.id)}
                                                >
                                                    {open ? 'Hide diff' : 'Show diff'}
                                                </button>
                                                <button type="button" className="sg-btn sg-btn-primary sg-btn-sm" onClick={() => setRestoreId(version.id)}>
                                                    Restore
                                                </button>
                                            </div>
                                        </div>
                                        {open && (
                                            <div className="mt-3 flex flex-col gap-3">
                                                <UnifiedDiff label="Subject" before={version.subject} after={form.data.subject} />
                                                <UnifiedDiff label="Body" before={version.body} after={form.data.body} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    <p className="text-xs text-muted">
                        Sample placeholders used for preview:{' '}
                        {Object.keys(definition.sample_placeholders).length > 0
                            ? Object.entries(definition.sample_placeholders)
                                  .map(([tag, value]) => `${tag}=${value}`)
                                  .join(', ')
                            : 'none'}
                        . See also{' '}
                        <Link href="/admin/settings/mail-sending" className="font-semibold underline">
                            Mail sending
                        </Link>
                        .
                    </p>
                </section>

                <ConfirmDialog
                    open={revertOpen}
                    title="Revert to default?"
                    description="This removes the saved override and uses the built-in subject and body again. Version history for the override is deleted with it."
                    confirmLabel="Revert to default"
                    destructive
                    onConfirm={revertToDefault}
                    onCancel={() => setRevertOpen(false)}
                />
                <ConfirmDialog
                    open={restoreId !== null}
                    title="Restore this version?"
                    description="The selected version’s subject and body become the current override. A new version entry is created for the restore."
                    confirmLabel="Restore version"
                    onConfirm={restoreVersion}
                    onCancel={() => setRestoreId(null)}
                />
            </>
        </WorkspaceLayout>
    );
}
