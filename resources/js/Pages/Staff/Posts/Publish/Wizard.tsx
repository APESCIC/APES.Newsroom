import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Copy, Share2 } from 'lucide-react';
import { FormEvent, useId, useRef, useState } from 'react';
import WorkspaceLayout from '../../../../Components/Layout/WorkspaceLayout';
import Badge, { type BadgeTone } from '../../../../Components/Workspace/ui/Badge';
import PillButton from '../../../../Components/Workspace/ui/PillButton';

type StepDef = { id: string; label: string; number: number; disabled?: boolean; note?: string };
type Draft = {
    visibility: string;
    mailing_lists: string[];
    newsletter_segment_id: number | null;
    newsletter_template: string | null;
    email_mode: string;
    website_mode: string;
    website_at: string | null;
    email_at: string | null;
    step: string;
};
type Target = {
    id: number;
    channel: string;
    channel_label: string;
    status: string;
    status_label: string;
    scheduled_for: string | null;
    result_message: string | null;
    campaign_id: number | null;
};
type Option = { value: string; label: string };
type SegmentOption = { id: number; name: string; newsletter: string | null };

type Props = {
    post: {
        id: number;
        title: string;
        slug: string;
        status: string;
        edit_url: string;
    };
    step: string;
    steps: StepDef[];
    draft: Draft;
    targets: Target[];
    recipientCounts: { total: number; included: number; excluded: number };
    visibilityOptions: Option[];
    mailingListOptions: Option[];
    segmentOptions: SegmentOption[];
    templateOptions: Option[];
    sender: { verified: boolean; message: string | null; identity: { address?: string; name?: string | null } | null };
    shareUrl: string;
    timezone: string;
};

function targetTone(status: string): BadgeTone {
    const tones: Record<string, BadgeTone> = {
        sent: 'ok',
        failed: 'error',
        scheduled: 'info',
        sending: 'info',
        pending: 'info',
        cancelled: 'neutral',
        skipped: 'neutral',
    };

    return tones[status] ?? 'neutral';
}

function shareLinks(url: string, title: string) {
    const encoded = encodeURIComponent(url);
    const text = encodeURIComponent(title);
    return [
        { id: 'x', label: 'X', href: `https://twitter.com/intent/tweet?url=${encoded}&text=${text}` },
        { id: 'facebook', label: 'Facebook', href: `https://www.facebook.com/sharer/sharer.php?u=${encoded}` },
        { id: 'linkedin', label: 'LinkedIn', href: `https://www.linkedin.com/sharing/share-offsite/?url=${encoded}` },
        { id: 'bluesky', label: 'Bluesky', href: `https://bsky.app/intent/compose?text=${text}%20${encoded}` },
        { id: 'whatsapp', label: 'WhatsApp', href: `https://wa.me/?text=${text}%20${encoded}` },
    ] as const;
}

function AudienceStep({
    postId,
    draft,
    visibilityOptions,
    mailingListOptions,
    segmentOptions,
    templateOptions,
    recipientCounts,
    sender,
}: {
    postId: number;
    draft: Draft;
    visibilityOptions: Option[];
    mailingListOptions: Option[];
    segmentOptions: SegmentOption[];
    templateOptions: Option[];
    recipientCounts: Props['recipientCounts'];
    sender: Props['sender'];
}) {
    const form = useForm({
        visibility: draft.visibility,
        mailing_lists: draft.mailing_lists ?? [],
        newsletter_segment_id: draft.newsletter_segment_id ? String(draft.newsletter_segment_id) : '',
        newsletter_template: draft.newsletter_template ?? '',
        email_mode: draft.email_mode === 'dont' ? 'dont' : draft.email_mode || 'now',
        next: 'when',
    });

    const toggleList = (value: string) => {
        const current = form.data.mailing_lists;
        form.setData(
            'mailing_lists',
            current.includes(value) ? current.filter((item) => item !== value) : [...current, value],
        );
    };

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/staff/posts/${postId}/publish-wizard/audience`);
    };

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-5" noValidate>
            <fieldset className="flex flex-col gap-3">
                <legend className="text-base font-semibold text-body">Who is this post for?</legend>
                {visibilityOptions.map((option) => (
                    <label key={option.value} className="flex items-start gap-2 text-sm text-body">
                        <input
                            type="radio"
                            name="visibility"
                            value={option.value}
                            checked={form.data.visibility === option.value}
                            onChange={() => form.setData('visibility', option.value)}
                            className="mt-1"
                        />
                        <span>{option.label}</span>
                    </label>
                ))}
            </fieldset>

            <fieldset className="flex flex-col gap-3">
                <legend className="text-base font-semibold text-body">Email audience</legend>
                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="email_mode_audience"
                        checked={form.data.email_mode !== 'dont'}
                        onChange={() => form.setData('email_mode', 'now')}
                        className="mt-1"
                    />
                    <span>Include an email send (timing chosen next)</span>
                </label>
                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="email_mode_audience"
                        checked={form.data.email_mode === 'dont'}
                        onChange={() => form.setData('email_mode', 'dont')}
                        className="mt-1"
                    />
                    <span>Do not email this post</span>
                </label>

                {form.data.email_mode !== 'dont' && (
                    <div className="flex flex-col gap-2 border-l-2 border-border pl-3">
                        {mailingListOptions.map((list) => (
                            <label key={list.value} className="flex gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.mailing_lists.includes(list.value)}
                                    onChange={() => toggleList(list.value)}
                                />
                                {list.label}
                            </label>
                        ))}
                        <label className="text-sm" htmlFor="newsletter_segment_id">
                            Segment (optional)
                            <select
                                id="newsletter_segment_id"
                                className="form-input mt-1 w-full"
                                value={form.data.newsletter_segment_id}
                                onChange={(e) => form.setData('newsletter_segment_id', e.target.value)}
                            >
                                <option value="">No segment</option>
                                {segmentOptions.map((segment) => (
                                    <option key={segment.id} value={String(segment.id)}>
                                        {segment.name}
                                        {segment.newsletter ? ` (${segment.newsletter})` : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {templateOptions.length > 0 && (
                            <label className="text-sm" htmlFor="newsletter_template">
                                Email template
                                <select
                                    id="newsletter_template"
                                    className="form-input mt-1 w-full"
                                    value={form.data.newsletter_template}
                                    onChange={(e) => form.setData('newsletter_template', e.target.value)}
                                >
                                    {templateOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <p className="text-sm text-muted" data-testid="recipient-count">
                            Estimated recipients after membership filter: {recipientCounts.included}
                            {recipientCounts.total !== recipientCounts.included
                                ? ` (${recipientCounts.excluded} excluded from ${recipientCounts.total} consented)`
                                : null}
                        </p>
                        {!sender.verified && sender.message ? (
                            <p className="text-sm text-danger" role="alert">
                                {sender.message}
                            </p>
                        ) : null}
                    </div>
                )}
            </fieldset>

            <div className="flex flex-wrap gap-2">
                <PillButton href={`/staff/posts/${postId}/edit`} variant="secondary" icon={ArrowLeft}>
                    Back to editor
                </PillButton>
                <PillButton type="submit" disabled={form.processing}>
                    Next: When
                </PillButton>
            </div>
        </form>
    );
}

function WhenStep({ postId, draft }: { postId: number; draft: Draft }) {
    const form = useForm({
        website_mode: draft.website_mode || 'now',
        email_mode: draft.email_mode || 'dont',
        website_at: draft.website_at || '',
        email_at: draft.email_at || '',
        next: 'confirm',
    });

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/staff/posts/${postId}/publish-wizard/when`);
    };

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-5" noValidate>
            <fieldset className="flex flex-col gap-3">
                <legend className="text-base font-semibold text-body">Website</legend>
                <label className="flex gap-2 text-sm">
                    <input
                        type="radio"
                        name="website_mode"
                        checked={form.data.website_mode === 'now'}
                        onChange={() => form.setData('website_mode', 'now')}
                    />
                    Publish now
                </label>
                <label className="flex gap-2 text-sm">
                    <input
                        type="radio"
                        name="website_mode"
                        checked={form.data.website_mode === 'schedule'}
                        onChange={() => form.setData('website_mode', 'schedule')}
                    />
                    Schedule
                </label>
                {form.data.website_mode === 'schedule' && (
                    <label className="text-sm" htmlFor="website_at">
                        Website time (Europe/London)
                        <input
                            id="website_at"
                            type="datetime-local"
                            className="form-input mt-1 w-full max-w-sm"
                            value={form.data.website_at}
                            onChange={(e) => form.setData('website_at', e.target.value)}
                            required
                        />
                    </label>
                )}
                {form.errors.website_at ? <p className="text-sm text-danger">{form.errors.website_at}</p> : null}
            </fieldset>

            <fieldset className="flex flex-col gap-3">
                <legend className="text-base font-semibold text-body">Email</legend>
                <label className="flex gap-2 text-sm">
                    <input
                        type="radio"
                        name="email_mode"
                        checked={form.data.email_mode === 'now'}
                        onChange={() => form.setData('email_mode', 'now')}
                    />
                    Send now (with website)
                </label>
                <label className="flex gap-2 text-sm">
                    <input
                        type="radio"
                        name="email_mode"
                        checked={form.data.email_mode === 'schedule'}
                        onChange={() => form.setData('email_mode', 'schedule')}
                    />
                    Schedule
                </label>
                <label className="flex gap-2 text-sm">
                    <input
                        type="radio"
                        name="email_mode"
                        checked={form.data.email_mode === 'dont'}
                        onChange={() => form.setData('email_mode', 'dont')}
                    />
                    Do not send
                </label>
                {form.data.email_mode === 'schedule' && (
                    <label className="text-sm" htmlFor="email_at">
                        Email time (Europe/London)
                        <input
                            id="email_at"
                            type="datetime-local"
                            className="form-input mt-1 w-full max-w-sm"
                            value={form.data.email_at}
                            onChange={(e) => form.setData('email_at', e.target.value)}
                            required
                        />
                    </label>
                )}
                {form.errors.email_at ? <p className="text-sm text-danger">{form.errors.email_at}</p> : null}
                {form.errors.email_mode ? <p className="text-sm text-danger">{form.errors.email_mode}</p> : null}
            </fieldset>

            <div className="flex flex-wrap gap-2">
                <PillButton href={`/staff/posts/${postId}/publish-wizard/audience`} variant="secondary" icon={ArrowLeft}>
                    Back
                </PillButton>
                <PillButton type="submit" disabled={form.processing}>
                    Next: Confirm
                </PillButton>
            </div>
        </form>
    );
}

function ConfirmStep({
    postId,
    draft,
    recipientCounts,
    visibilityOptions,
    mailingListOptions,
}: {
    postId: number;
    draft: Draft;
    recipientCounts: Props['recipientCounts'];
    visibilityOptions: Option[];
    mailingListOptions: Option[];
}) {
    const form = useForm({});
    const [submitting, setSubmitting] = useState(false);
    // Synchronous lock for double-clicks before React re-renders; never read during render (#684).
    const submitLock = useRef(false);
    const visibilityLabel = visibilityOptions.find((o) => o.value === draft.visibility)?.label ?? draft.visibility;
    const lists = mailingListOptions
        .filter((o) => draft.mailing_lists.includes(o.value))
        .map((o) => o.label)
        .join(', ');
    const publishBusy = form.processing || submitting;

    const onPublish = (event: FormEvent) => {
        event.preventDefault();
        if (submitLock.current || form.processing || submitting) {
            return;
        }
        submitLock.current = true;
        setSubmitting(true);
        form.post(`/staff/posts/${postId}/publish-wizard/confirm`, {
            onFinish: () => {
                submitLock.current = false;
                setSubmitting(false);
            },
        });
    };

    return (
        <form onSubmit={onPublish} className="flex flex-col gap-5">
            <section className="flex flex-col gap-3" aria-labelledby="confirm-summary-heading">
                <h2 id="confirm-summary-heading" className="text-base font-semibold text-body">
                    Summary
                </h2>
                <div className="sg-card flex flex-col gap-3 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p className="text-sm font-semibold text-body">Audience</p>
                            <p className="text-sm text-muted">
                                Website: {visibilityLabel}. Email:{' '}
                                {draft.email_mode === 'dont' ? 'not sending' : lists || 'selected lists/segment'}. Recipients:{' '}
                                {draft.email_mode === 'dont' ? '0' : recipientCounts.included}.
                            </p>
                        </div>
                        <PillButton href={`/staff/posts/${postId}/publish-wizard/audience`} variant="ghost" size="sm">
                            Edit
                        </PillButton>
                    </div>
                    <div className="flex flex-wrap items-start justify-between gap-2 border-t border-border pt-3">
                        <div>
                            <p className="text-sm font-semibold text-body">When</p>
                            <p className="text-sm text-muted">
                                Website: {draft.website_mode === 'schedule' ? `schedule ${draft.website_at}` : 'now'}. Email:{' '}
                                {draft.email_mode === 'dont'
                                    ? 'do not send'
                                    : draft.email_mode === 'schedule'
                                      ? `schedule ${draft.email_at}`
                                      : 'now'}
                                . Times in Europe/London.
                            </p>
                        </div>
                        <PillButton href={`/staff/posts/${postId}/publish-wizard/when`} variant="ghost" size="sm">
                            Edit
                        </PillButton>
                    </div>
                    <div className="flex flex-wrap items-start justify-between gap-2 border-t border-border pt-3">
                        <div>
                            <p className="text-sm font-semibold text-body">Social</p>
                            <p className="text-sm text-muted">Skipped for now (v1.34.0).</p>
                        </div>
                    </div>
                </div>
            </section>

            <div className="flex flex-wrap gap-2">
                <PillButton href={`/staff/posts/${postId}/publish-wizard/when`} variant="secondary" icon={ArrowLeft}>
                    Back
                </PillButton>
                <PillButton type="submit" disabled={publishBusy} icon={Check} data-testid="publish-confirm">
                    {publishBusy ? 'Publishing…' : 'Publish'}
                </PillButton>
            </div>
        </form>
    );
}

function ResultStep({
    postId,
    postSlug,
    postTitle,
    targets,
    shareUrl,
}: {
    postId: number;
    postSlug: string;
    postTitle: string;
    targets: Target[];
    shareUrl: string;
}) {
    const [copied, setCopied] = useState(false);
    const links = shareLinks(shareUrl, postTitle);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(shareUrl);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="flex flex-col gap-5">
            <section aria-labelledby="result-heading" className="flex flex-col gap-3">
                <h2 id="result-heading" className="text-base font-semibold text-body">
                    Per-channel status
                </h2>
                <ul className="flex flex-col gap-3">
                    {targets.map((target) => {
                        const tone = targetTone(target.status);
                        return (
                            <li key={target.channel} className="sg-card flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-semibold text-body">{target.channel_label}</p>
                                        <Badge tone={tone}>{target.status_label}</Badge>
                                    </div>
                                    {target.result_message ? <p className="text-sm text-muted">{target.result_message}</p> : null}
                                    {target.scheduled_for ? (
                                        <p className="text-sm text-muted">Scheduled: {new Date(target.scheduled_for).toLocaleString('en-GB')}</p>
                                    ) : null}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {target.status === 'failed' && (
                                        <PillButton
                                            size="sm"
                                            variant="secondary"
                                            onClick={() => router.post(`/staff/posts/${postId}/publish-wizard/retry/${target.channel}`)}
                                        >
                                            Retry
                                        </PillButton>
                                    )}
                                    {target.status === 'scheduled' && (
                                        <PillButton
                                            size="sm"
                                            variant="danger"
                                            onClick={() => router.post(`/staff/posts/${postId}/publish-wizard/cancel/${target.channel}`)}
                                        >
                                            Cancel
                                        </PillButton>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </section>

            <section aria-labelledby="share-heading" className="flex flex-col gap-3">
                <h2 id="share-heading" className="flex items-center gap-2 text-base font-semibold text-body">
                    <Share2 className="h-4 w-4" aria-hidden="true" />
                    Share
                </h2>
                <p className="text-sm text-muted break-all">{shareUrl}</p>
                <div className="flex flex-wrap gap-2">
                    {links.map((link) => (
                        <a key={link.id} href={link.href} target="_blank" rel="noreferrer" className="sg-btn sg-btn-secondary sg-btn-sm">
                            {link.label}
                        </a>
                    ))}
                    <PillButton type="button" size="sm" variant="secondary" icon={Copy} onClick={copy}>
                        {copied ? 'Copied' : 'Copy link'}
                    </PillButton>
                </div>
            </section>

            <div className="flex flex-wrap gap-2">
                <PillButton href={`/staff/posts/${postId}/edit`} variant="secondary">
                    Back to editor
                </PillButton>
                <PillButton href={`/articles/${postSlug}`} variant="ghost">
                    View post
                </PillButton>
            </div>
        </div>
    );
}

export default function Wizard(props: Props) {
    const { post, step, steps, draft, targets, recipientCounts, visibilityOptions, mailingListOptions, segmentOptions, templateOptions, sender, shareUrl, timezone } =
        props;
    const headingId = useId();

    return (
        <WorkspaceLayout area="Staff" title={`Publish: ${post.title}`} active="posts">
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-3 py-6 sm:px-4">
                <header className="flex flex-col gap-2">
                    <p className="text-sm text-muted">Publish wizard · {timezone}</p>
                    <h1 id={headingId} className="text-2xl font-bold tracking-tight text-body">
                        {post.title}
                    </h1>
                </header>

                <nav aria-label="Publish steps" className="overflow-x-auto">
                    <ol className="flex min-w-[320px] gap-2">
                        {steps.map((item) => {
                            const isCurrent = item.id === step;
                            const base =
                                'flex min-w-0 flex-1 flex-col rounded-lg border px-2 py-2 text-left text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand';
                            const state = isCurrent ? ' border-brand bg-brand/10 text-body' : ' border-border text-muted';
                            if (item.disabled) {
                                return (
                                    <li key={item.id} className={`${base}${state} opacity-60`} aria-disabled="true">
                                        <span className="font-semibold">
                                            {item.number}. {item.label}
                                        </span>
                                        {item.note ? <span className="text-xs">{item.note}</span> : null}
                                    </li>
                                );
                            }
                            return (
                                <li key={item.id} className="min-w-0 flex-1">
                                    <Link
                                        href={`/staff/posts/${post.id}/publish-wizard/${item.id}`}
                                        className={`${base}${state}`}
                                        aria-current={isCurrent ? 'step' : undefined}
                                    >
                                        <span className="font-semibold">
                                            {item.number}. {item.label}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ol>
                </nav>

                <div className="sg-card p-5 sm:p-6">
                    {step === 'audience' && (
                        <AudienceStep
                            postId={post.id}
                            draft={draft}
                            visibilityOptions={visibilityOptions}
                            mailingListOptions={mailingListOptions}
                            segmentOptions={segmentOptions}
                            templateOptions={templateOptions}
                            recipientCounts={recipientCounts}
                            sender={sender}
                        />
                    )}
                    {step === 'when' && <WhenStep postId={post.id} draft={draft} />}
                    {step === 'confirm' && (
                        <ConfirmStep
                            postId={post.id}
                            draft={draft}
                            recipientCounts={recipientCounts}
                            visibilityOptions={visibilityOptions}
                            mailingListOptions={mailingListOptions}
                        />
                    )}
                    {step === 'result' && (
                        <ResultStep
                            postId={post.id}
                            postSlug={post.slug}
                            postTitle={post.title}
                            targets={targets}
                            shareUrl={shareUrl}
                        />
                    )}
                </div>
            </div>
        </WorkspaceLayout>
    );
}
