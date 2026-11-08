import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Monitor, Smartphone } from 'lucide-react';
import { useId, useMemo, useRef, useState, type FormEvent } from 'react';
import { refusalMessage, xsrfToken } from '../../../Components/Admin/EmailDomains/TestSendPanel';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import ConfirmDialog from '../../../Components/Workspace/ui/ConfirmDialog';
import PillButton from '../../../Components/Workspace/ui/PillButton';
import type { SharedPageProps } from '../../../types/page';

export type TemplateSettings = {
    sign_off: string;
    read_more_label: string;
    members_cta: string;
    paid_cta: string;
    show_hero: boolean;
    show_excerpt: boolean;
    show_full_post: boolean;
};

export type TemplateDraft = {
    subject: string;
    intro: string;
    settings: TemplateSettings;
};

type Version = {
    id: number;
    subject: string;
    intro: string;
    settings: TemplateSettings;
    editor: string | null;
    created_at: string | null;
};

type TextField = 'subject' | 'intro' | 'sign_off' | 'read_more_label' | 'members_cta' | 'paid_cta';

type Visibility = 'public' | 'members' | 'paid';

type PreviewPost = { id: number; title: string; visibility: Visibility };

const VISIBILITIES: Array<{ value: Visibility; label: string }> = [
    { value: 'public', label: 'Public' },
    { value: 'members', label: 'Members' },
    { value: 'paid', label: 'Paid' },
];

const TAG_PATTERN = /\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/gu;

const FIELD_LABELS: Record<TextField, string> = {
    subject: 'subject',
    intro: 'introduction',
    sign_off: 'sign-off',
    read_more_label: 'button label',
    members_cta: 'members-only wording',
    paid_cta: 'paid wording',
};

export function extractTags(value: string): string[] {
    const found = new Set<string>();
    for (const match of value.matchAll(TAG_PATTERN)) {
        found.add(match[1]);
    }
    return [...found];
}

/** Placeholder problems per field, matching the server-side check on save. */
export function placeholderProblems(draft: TemplateDraft, allowed: string[], postOnly: string[]): string[] {
    const problems: string[] = [];
    const check = (field: TextField, value: string, allowList: string[]) => {
        for (const tag of extractTags(value)) {
            if (!allowList.includes(tag)) {
                problems.push(
                    tag === 'recipient_first_name' && allowList === postOnly
                        ? `{{ recipient_first_name }} can't be used in the ${FIELD_LABELS[field]}.`
                        : `Unknown placeholder {{ ${tag} }} in the ${FIELD_LABELS[field]}.`,
                );
            }
        }
    };

    check('subject', draft.subject, allowed);
    check('intro', draft.intro, allowed);
    check('sign_off', draft.settings.sign_off, allowed);
    check('read_more_label', draft.settings.read_more_label, allowed);
    check('members_cta', draft.settings.members_cta, postOnly);
    check('paid_cta', draft.settings.paid_cta, postOnly);

    return problems;
}

function formatTime(iso: string | null): string {
    if (!iso) return 'Unknown time';
    return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

function FieldDiff({ label, before, after }: { label: string; before: string; after: string }) {
    if (before === after) {
        return <p className="text-xs text-muted">{label}: unchanged</p>;
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

export default function NewsletterTemplateEdit({
    target,
    newsletter,
    template,
    defaults,
    placeholders,
    postPlaceholders,
    versions,
    testRecipient,
    previewPosts = [],
}: {
    target: string;
    newsletter: { id: number; name: string } | null;
    template: TemplateDraft & { is_overridden: boolean; updated_at: string | null };
    defaults: TemplateDraft;
    placeholders: string[];
    postPlaceholders: string[];
    samplePlaceholders: Record<string, string>;
    versions: Version[];
    testRecipient: string;
    previewPosts?: PreviewPost[];
}) {
    const { flash, errors } = usePage<SharedPageProps>().props;
    const form = useForm<TemplateDraft>({ subject: template.subject, intro: template.intro, settings: template.settings });
    const fieldRefs = useRef<Partial<Record<TextField, HTMLInputElement | HTMLTextAreaElement | null>>>({});
    const [activeField, setActiveField] = useState<TextField>('intro');
    const [revertOpen, setRevertOpen] = useState(false);
    const [restoreId, setRestoreId] = useState<number | null>(null);
    const [expandedVersion, setExpandedVersion] = useState<number | null>(null);
    const [postId, setPostId] = useState<number | null>(previewPosts[0]?.id ?? null);
    const [visibility, setVisibility] = useState<Visibility>(previewPosts[0]?.visibility ?? 'public');
    const [previewWidth, setPreviewWidth] = useState<'desktop' | 'mobile'>('desktop');
    const [preview, setPreview] = useState<{ subject: string; html: string } | null>(null);
    const [previewBusy, setPreviewBusy] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [testBusy, setTestBusy] = useState(false);
    const [testError, setTestError] = useState<string | null>(null);
    const [testMessage, setTestMessage] = useState<string | null>(null);
    const postSelectId = useId();
    const ids = {
        subject: useId(),
        intro: useId(),
        sign_off: useId(),
        read_more_label: useId(),
        members_cta: useId(),
        paid_cta: useId(),
    } satisfies Record<TextField, string>;

    const base = `/staff/newsletter-templates/${target}`;
    const title = newsletter ? `${newsletter.name} email template` : 'Default newsletter template';
    const problems = useMemo(() => placeholderProblems(form.data, placeholders, postPlaceholders), [form.data, placeholders, postPlaceholders]);
    const canSave = problems.length === 0 && form.data.settings.read_more_label.trim() !== '' && !form.processing;
    const serverErrors = errors?.template;

    const valueOf = (field: TextField): string => (field === 'subject' || field === 'intro' ? form.data[field] : form.data.settings[field]);

    const setField = (field: TextField, value: string) => {
        if (field === 'subject' || field === 'intro') {
            form.setData(field, value);
            return;
        }
        form.setData('settings', { ...form.data.settings, [field]: value });
    };

    const setSection = (key: 'show_hero' | 'show_excerpt' | 'show_full_post', value: boolean) => {
        form.setData('settings', { ...form.data.settings, [key]: value });
    };

    const insertPlaceholder = (tag: string) => {
        const field = fieldRefs.current[activeField];
        const current = valueOf(activeField);
        const insertion = `{{ ${tag} }}`;
        const start = field?.selectionStart ?? current.length;
        const end = field?.selectionEnd ?? current.length;
        setField(activeField, `${current.slice(0, start)}${insertion}${current.slice(end)}`);
        requestAnimationFrame(() => {
            if (!field) return;
            field.focus();
            const caret = start + insertion.length;
            field.setSelectionRange(caret, caret);
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!canSave) return;
        form.put(base, { preserveScroll: true });
    };

    const postDraft = async (path: 'preview' | 'test'): Promise<{ status: number; payload: unknown } | null> => {
        if (postId === null) return null;
        const response = await fetch(`${base}/${path}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ ...form.data, post_id: postId, visibility }),
        });
        return { status: response.status, payload: await response.json().catch(() => null) };
    };

    const runPreview = async () => {
        setPreviewBusy(true);
        setPreviewError(null);
        try {
            const result = await postDraft('preview');
            if (result === null) return;
            const body = (result.payload ?? {}) as { subject?: unknown; html?: unknown };
            if (result.status !== 200 || typeof body.subject !== 'string' || typeof body.html !== 'string') {
                setPreviewError(result.status === 200 ? 'The preview response could not be read.' : refusalMessage(result.status, result.payload));
                return;
            }
            setPreview({ subject: body.subject, html: body.html });
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
            const result = await postDraft('test');
            if (result === null) return;
            const body = (result.payload ?? {}) as { message?: unknown };
            if (result.status !== 200) {
                setTestError(refusalMessage(result.status, result.payload));
                return;
            }
            setTestMessage(typeof body.message === 'string' ? body.message : 'Test email queued.');
        } catch {
            setTestError('The test email could not be sent. Check your connection and try again.');
        } finally {
            setTestBusy(false);
        }
    };

    const restoreVersion = () => {
        if (restoreId === null) return;
        router.post(`${base}/versions/${restoreId}/restore`, {}, { preserveScroll: true, onFinish: () => setRestoreId(null) });
    };

    const revert = () => {
        router.post(`${base}/revert`, {}, { preserveScroll: true, onFinish: () => setRevertOpen(false) });
    };

    const textField = (field: TextField, label: string, options: { multiline?: boolean; rows?: number; maxLength: number; help?: string }) => {
        const common = {
            id: ids[field],
            value: valueOf(field),
            maxLength: options.maxLength,
            onFocus: () => setActiveField(field),
            'aria-describedby': options.help ? `${ids[field]}-help` : undefined,
            className: `form-input font-normal ${options.multiline ? 'font-mono text-sm' : ''}`,
        };
        const error = (form.errors as Record<string, string | undefined>)[field === 'subject' || field === 'intro' ? field : `settings.${field}`];

        return (
            <div className="flex flex-col gap-1">
                <label htmlFor={ids[field]} className="text-sm font-semibold text-body">
                    {label}
                </label>
                {options.multiline ? (
                    <textarea
                        {...common}
                        ref={(element) => {
                            fieldRefs.current[field] = element;
                        }}
                        rows={options.rows ?? 4}
                        onChange={(event) => setField(field, event.target.value)}
                    />
                ) : (
                    <input
                        {...common}
                        ref={(element) => {
                            fieldRefs.current[field] = element;
                        }}
                        type="text"
                        autoComplete="off"
                        onChange={(event) => setField(field, event.target.value)}
                    />
                )}
                {options.help && (
                    <p id={`${ids[field]}-help`} className="text-xs text-muted">
                        {options.help}
                    </p>
                )}
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
            </div>
        );
    };

    const postOnlyField = activeField === 'members_cta' || activeField === 'paid_cta';

    return (
        <WorkspaceLayout
            area="Staff"
            active="newsletters"
            title={title}
            subtitle={newsletter ? 'This newsletter’s own version of the newsletter email' : 'Used by every newsletter without its own version'}
            actions={
                <PillButton href={newsletter ? `/staff/newsletters/${newsletter.id}/edit` : '/staff/newsletters'} variant="secondary" icon={ArrowLeft}>
                    {newsletter ? 'Back to newsletter' : 'All newsletters'}
                </PillButton>
            }
        >
            <>
                {flash?.status && (
                    <p role="status" className="sg-card border-l-4 !border-l-[var(--color-sg-success)] px-4 py-3 text-sm text-body">
                        {flash.status}
                    </p>
                )}

                <section className="sg-card flex flex-col gap-3 p-5 text-sm text-body sm:p-6" aria-labelledby="newsletter-template-meta-heading">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id="newsletter-template-meta-heading" className="text-base font-semibold">
                            Template
                        </h2>
                        {template.is_overridden ? (
                            <Badge tone="info">{newsletter ? 'Own version' : 'Edited'}</Badge>
                        ) : (
                            <Badge tone="neutral">{newsletter ? 'Uses the default template' : 'Built-in'}</Badge>
                        )}
                    </div>
                    <p className="text-muted">
                        The layout, the test banner, the footer, the unsubscribe and preferences links and the List-Unsubscribe header are added
                        automatically and can’t be edited. Campaigns keep the template they were created with, so editing it never changes emails already
                        sent or queued.
                    </p>
                    {newsletter && !template.is_overridden && (
                        <p className="text-muted">The form starts from the default template. Saving creates this newsletter’s own version.</p>
                    )}
                </section>

                <form onSubmit={submit} className="sg-card flex flex-col gap-5 p-5 sm:p-6" aria-labelledby="newsletter-template-editor-heading">
                    <h2 id="newsletter-template-editor-heading" className="text-base font-semibold text-body">
                        Wording
                    </h2>

                    <div>
                        <p className="mb-2 text-sm font-semibold text-body">Insert placeholder</p>
                        <div className="flex flex-wrap gap-2" role="group" aria-label="Allowed placeholders">
                            {placeholders.map((tag) => {
                                const unavailable = postOnlyField && !postPlaceholders.includes(tag);
                                return (
                                    <button
                                        key={tag}
                                        type="button"
                                        className="sg-btn sg-btn-secondary sg-btn-sm font-mono"
                                        disabled={unavailable}
                                        onClick={() => insertPlaceholder(tag)}
                                    >
                                        {`{{ ${tag} }}`}
                                    </button>
                                );
                            })}
                        </div>
                        <p className="mt-1 text-xs text-muted" aria-live="polite">
                            Inserts into the {FIELD_LABELS[activeField]}.
                            {postOnlyField ? ' The reader’s first name isn’t available here, because this wording is set once for the whole campaign.' : ''}
                        </p>
                    </div>

                    {textField('subject', 'Subject', { maxLength: 255, help: 'Test sends add “[TEST]” in front.' })}
                    {textField('intro', 'Introduction (Markdown, optional)', {
                        multiline: true,
                        rows: 5,
                        maxLength: 20000,
                        help: 'Shown above the post title. Leave empty for no introduction.',
                    })}
                    {textField('read_more_label', 'Button label', { maxLength: 80 })}
                    {textField('sign_off', 'Sign-off (Markdown, optional)', { multiline: true, rows: 3, maxLength: 2000 })}
                    {textField('members_cta', 'Members-only wording', {
                        multiline: true,
                        rows: 2,
                        maxLength: 500,
                        help: 'Added under the excerpt of members-only posts, which are never sent in full.',
                    })}
                    {textField('paid_cta', 'Paid wording', {
                        multiline: true,
                        rows: 2,
                        maxLength: 500,
                        help: 'Added under the excerpt of paid posts, which are never sent in full.',
                    })}

                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-1 text-sm font-semibold text-body">Sections shown</legend>
                        <label className="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" checked={form.data.settings.show_hero} onChange={(event) => setSection('show_hero', event.target.checked)} />
                            Hero image
                        </label>
                        <label className="flex items-center gap-2 text-sm text-body">
                            <input
                                type="checkbox"
                                checked={form.data.settings.show_full_post}
                                onChange={(event) => setSection('show_full_post', event.target.checked)}
                            />
                            Full post (public posts; otherwise the excerpt is sent)
                        </label>
                        <label className="flex items-center gap-2 text-sm text-body">
                            <input
                                type="checkbox"
                                checked={form.data.settings.show_excerpt}
                                disabled={!form.data.settings.show_full_post}
                                onChange={(event) => setSection('show_excerpt', event.target.checked)}
                            />
                            Excerpt above the full post
                        </label>
                    </fieldset>

                    {problems.length > 0 && (
                        <ul role="alert" className="list-disc pl-5 text-sm text-danger">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    )}
                    {serverErrors && (
                        <p role="alert" className="text-sm text-danger">
                            {serverErrors}
                        </p>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className="sg-btn sg-btn-primary" disabled={!canSave || !form.isDirty}>
                            {form.processing ? 'Saving…' : 'Save template'}
                        </button>
                        <button type="button" className="sg-btn sg-btn-secondary" disabled={form.processing} onClick={() => form.setData(defaults)}>
                            Fill in the built-in wording
                        </button>
                        {template.is_overridden && (
                            <button type="button" className="sg-btn sg-btn-danger" onClick={() => setRevertOpen(true)}>
                                {newsletter ? 'Revert to the default template' : 'Revert to built-in'}
                            </button>
                        )}
                    </div>
                </form>

                <section className="sg-card flex flex-col gap-4 p-5 sm:p-6" aria-labelledby="newsletter-template-preview-heading">
                    <h2 id="newsletter-template-preview-heading" className="text-base font-semibold text-body">
                        Preview with a real post
                    </h2>
                    {previewPosts.length === 0 ? (
                        <p className="text-sm text-muted">Publish a post first to preview the newsletter email with it.</p>
                    ) : (
                        <>
                            <div className="flex flex-col gap-1">
                                <label htmlFor={postSelectId} className="text-sm font-semibold text-body">
                                    Post
                                </label>
                                <select
                                    id={postSelectId}
                                    className="form-input"
                                    value={postId ?? ''}
                                    onChange={(event) => setPostId(Number(event.target.value))}
                                >
                                    {previewPosts.map((post) => (
                                        <option key={post.id} value={post.id}>
                                            {post.title}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <fieldset className="flex flex-wrap items-center gap-2">
                                <legend className="mb-1 text-sm font-semibold text-body">Show the post as</legend>
                                {VISIBILITIES.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        className={`sg-btn sg-btn-sm ${visibility === option.value ? 'sg-btn-primary' : 'sg-btn-secondary'}`}
                                        aria-pressed={visibility === option.value}
                                        onClick={() => setVisibility(option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </fieldset>
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
                                <button
                                    type="button"
                                    className="sg-btn sg-btn-secondary sg-btn-sm"
                                    disabled={previewBusy || problems.length > 0}
                                    onClick={() => void runPreview()}
                                >
                                    {previewBusy ? 'Rendering…' : 'Refresh preview'}
                                </button>
                            </div>
                            {previewError && (
                                <p role="alert" className="text-sm text-danger">
                                    {previewError}
                                </p>
                            )}
                            {preview ? (
                                <div className="flex flex-col gap-2">
                                    <p className="text-sm text-body">
                                        <span className="font-semibold">Subject:</span> {preview.subject}
                                    </p>
                                    <div
                                        className={`mx-auto w-full overflow-hidden rounded border border-border bg-white ${previewWidth === 'mobile' ? 'max-w-sm' : 'max-w-3xl'}`}
                                    >
                                        <iframe title="Newsletter email preview" sandbox="" srcDoc={preview.html} className="h-[36rem] w-full border-0" />
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-muted">Choose a post and click “Refresh preview” to render the current draft.</p>
                            )}
                        </>
                    )}
                </section>

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="newsletter-template-test-heading">
                    <h2 id="newsletter-template-test-heading" className="text-base font-semibold text-body">
                        Send a test
                    </h2>
                    <p className="text-sm text-muted">
                        Sends the current draft with the post chosen above to you ({testRecipient}) through the normal campaign test send. Test sends are
                        throttled.
                    </p>
                    <button
                        type="button"
                        className="sg-btn sg-btn-primary self-start"
                        disabled={testBusy || postId === null || problems.length > 0}
                        onClick={() => void runTest()}
                    >
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

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="newsletter-template-versions-heading">
                    <h2 id="newsletter-template-versions-heading" className="text-base font-semibold text-body">
                        Version history
                    </h2>
                    {versions.length === 0 ? (
                        <p className="text-sm text-muted">No saved versions yet. Saving creates the first one.</p>
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
                                                <FieldDiff label="Subject" before={version.subject} after={form.data.subject} />
                                                <FieldDiff label="Introduction" before={version.intro} after={form.data.intro} />
                                                <FieldDiff label="Button label" before={version.settings.read_more_label} after={form.data.settings.read_more_label} />
                                                <FieldDiff label="Sign-off" before={version.settings.sign_off} after={form.data.settings.sign_off} />
                                                <FieldDiff label="Members-only wording" before={version.settings.members_cta} after={form.data.settings.members_cta} />
                                                <FieldDiff label="Paid wording" before={version.settings.paid_cta} after={form.data.settings.paid_cta} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>

                <ConfirmDialog
                    open={revertOpen}
                    title={newsletter ? 'Revert to the default template?' : 'Revert to the built-in email?'}
                    description={
                        newsletter
                            ? 'This removes this newsletter’s own version and its history. The newsletter then sends with the default template.'
                            : 'This removes the edited default template and its history. Newsletters without their own version then send the built-in email.'
                    }
                    confirmLabel="Revert"
                    destructive
                    onConfirm={revert}
                    onCancel={() => setRevertOpen(false)}
                />
                <ConfirmDialog
                    open={restoreId !== null}
                    title="Restore this version?"
                    description="The selected version becomes the current template. A new version entry is created for the restore."
                    confirmLabel="Restore version"
                    onConfirm={restoreVersion}
                    onCancel={() => setRestoreId(null)}
                />
            </>
        </WorkspaceLayout>
    );
}
