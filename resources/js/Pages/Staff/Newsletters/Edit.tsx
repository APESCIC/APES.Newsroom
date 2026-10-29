import { router, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import PillButton from '../../../Components/Workspace/ui/PillButton';

type NewsletterData = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    legacy_list: string | null;
    archived_at: string | null;
    status: 'active' | 'archived';
    sender_name: string | null;
    sender_email: string | null;
    reply_to: string | null;
    subscribe_on_signup: boolean;
    sender_verification: {
        verified: boolean;
        status: string | null;
        message: string | null;
        checked_at: string | null;
    };
    send_blocks: {
        reasons: string[];
        last_blocked_at: string | null;
    };
    channel: string | null;
    show_on_home: boolean;
    show_in_nav: boolean;
    display_order: number;
    public_label: string | null;
    public_description: string | null;
};

type ChannelOption = { value: string; label: string; slug: string; taken_by: string | null };

type DomainOption = { domain: string; status: string; can_send: boolean };

type SenderOption = { address: string; label: string | null; domain: string; can_send: boolean };

export function SenderSelect({
    value,
    original,
    options,
    reservedLocalParts,
    error,
    onChange,
}: {
    value: string;
    original: string;
    options: SenderOption[];
    reservedLocalParts: string[];
    error?: string;
    onChange: (value: string) => void;
}) {
    const savedAddress = original.toLowerCase();
    const listed = options.some((option) => option.address === savedAddress);
    const helpId = 'sender_email-help';
    const errorId = 'sender_email-error';

    return (
        <div>
            <label className="block text-sm font-semibold" htmlFor="sender_email">
                Sender email
                <select
                    id="sender_email"
                    className="form-input mt-1"
                    value={value.toLowerCase()}
                    aria-describedby={error ? `${helpId} ${errorId}` : helpId}
                    aria-invalid={error ? true : undefined}
                    onChange={(e) => onChange(e.target.value)}
                >
                    <option value="">Choose a sender</option>
                    {savedAddress !== '' && !listed && <option value={savedAddress}>{savedAddress} (current, no longer on the allowed list)</option>}
                    {options.map((option) => (
                        <option key={option.address} value={option.address} disabled={!option.can_send && option.address !== savedAddress}>
                            {option.address}
                            {option.label ? ` · ${option.label}` : ''}
                            {option.can_send ? '' : ' (domain not verified)'}
                        </option>
                    ))}
                </select>
            </label>
            <p id={helpId} className="mt-1 text-xs text-muted">
                Choose from the From addresses an admin has allowed under Settings → Email sending domains. Only addresses on domains Mailgun has
                verified can be chosen.{reservedLocalParts.length > 0 ? ` Reserved names (${reservedLocalParts.join(', ')}) are never allowed.` : ''}
            </p>
            {error && (
                <p id={errorId} className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

function splitAddress(address: string | null | undefined): { local: string; domain: string } {
    if (!address || !address.includes('@')) return { local: address ?? '', domain: '' };
    const at = address.lastIndexOf('@');
    return { local: address.slice(0, at), domain: address.slice(at + 1).toLowerCase() };
}

function joinAddress(local: string, domain: string): string {
    const trimmed = local.trim();
    return trimmed === '' || domain === '' ? '' : `${trimmed}@${domain}`;
}

function AddressPicker({
    id,
    label,
    local,
    domain,
    domains,
    unavailableNote,
    help,
    error,
    onLocalChange,
    onDomainChange,
}: {
    id: string;
    label: string;
    local: string;
    domain: string;
    domains: string[];
    unavailableNote: string;
    help: string;
    error?: string;
    onLocalChange: (value: string) => void;
    onDomainChange: (value: string) => void;
}) {
    const options = domain !== '' && !domains.includes(domain) ? [domain, ...domains] : domains;
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const describedBy = error ? `${helpId} ${errorId}` : helpId;

    return (
        <fieldset aria-describedby={describedBy} aria-invalid={error ? true : undefined}>
            <legend className="text-sm font-semibold">{label}</legend>
            <div className="mt-1 flex flex-wrap items-center gap-1">
                <label className="sr-only" htmlFor={`${id}_local`}>
                    {label}: name before the @
                </label>
                <input
                    id={`${id}_local`}
                    className="form-input min-w-0 flex-1"
                    value={local}
                    autoComplete="off"
                    spellCheck={false}
                    onChange={(e) => onLocalChange(e.target.value)}
                />
                <span aria-hidden="true">@</span>
                <label className="sr-only" htmlFor={`${id}_domain`}>
                    {label}: domain
                </label>
                <select id={`${id}_domain`} className="form-input !w-auto" value={domain} onChange={(e) => onDomainChange(e.target.value)}>
                    <option value="">Choose a domain</option>
                    {options.map((option) => (
                        <option key={option} value={option}>
                            {option}
                            {domains.includes(option) ? '' : ` (${unavailableNote})`}
                        </option>
                    ))}
                </select>
            </div>
            <p id={helpId} className="mt-1 text-xs text-muted">
                {help}
            </p>
            {error && (
                <p id={errorId} className="text-sm text-danger">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

export default function NewsletterEdit({
    newsletter,
    segments = [],
    otherNewsletters = [],
    channelOptions = [],
    domainOptions = [],
    senderOptions = [],
    defaultReplyTo = null,
    reservedLocalParts = [],
}: {
    newsletter: NewsletterData | null;
    segments?: Array<{ id: number; name: string; also: string }>;
    otherNewsletters?: Array<{ id: number; name: string }>;
    channelOptions?: ChannelOption[];
    domainOptions?: DomainOption[];
    senderOptions?: SenderOption[];
    defaultReplyTo?: string | null;
    reservedLocalParts?: string[];
}) {
    const replyTo = splitAddress(newsletter?.reply_to);
    const replyDomains = domainOptions.map((option) => option.domain);
    const form = useForm({
        name: newsletter?.name ?? '',
        slug: newsletter?.slug ?? '',
        description: newsletter?.description ?? '',
        sender_name: newsletter?.sender_name ?? '',
        sender_email: newsletter?.sender_email ?? '',
        reply_local: replyTo.local,
        reply_domain: replyTo.domain,
        subscribe_on_signup: newsletter?.subscribe_on_signup ?? false,
        channel: newsletter?.channel ?? '',
        show_on_home: newsletter?.show_on_home ?? false,
        show_in_nav: newsletter?.show_in_nav ?? false,
        display_order: newsletter?.display_order ?? 0,
        public_label: newsletter?.public_label ?? '',
        public_description: newsletter?.public_description ?? '',
    });
    const addressErrors = form.errors as Partial<Record<'sender_email' | 'reply_to', string>>;
    const slugLocked = Boolean(newsletter?.channel);
    const linkedChannel = channelOptions.find((option) => option.value === form.data.channel);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform(({ reply_local, reply_domain, ...data }) => ({
            ...data,
            reply_to: joinAddress(reply_local, reply_domain),
        }));
        if (newsletter) {
            form.patch(`/staff/newsletters/${newsletter.id}`);
        } else {
            form.post('/staff/newsletters');
        }
    };

    return (
        <WorkspaceLayout
            area="Staff"
            active="newsletters"
            title={newsletter ? 'Edit newsletter' : 'New newsletter'}
            documentTitle={newsletter ? `Edit: ${newsletter.name}` : undefined}
            subtitle={newsletter?.name}
            actions={
                <PillButton href="/staff/newsletters" variant="secondary" icon={Mail}>
                    All newsletters
                </PillButton>
            }
        >
            <form onSubmit={submit} className="sg-card space-y-4 p-5 sm:p-6">
                <label className="block text-sm font-semibold" htmlFor="name">
                    Name
                    <input id="name" className="form-input mt-1" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </label>
                {form.errors.name && <p className="text-sm text-danger">{form.errors.name}</p>}
                <label className="block text-sm font-semibold" htmlFor="slug">
                    Slug
                    <input
                        id="slug"
                        className="form-input mt-1 read-only:!bg-white/40"
                        value={form.data.slug}
                        readOnly={slugLocked}
                        aria-describedby={slugLocked ? 'slug-locked' : undefined}
                        onChange={(e) => form.setData('slug', e.target.value)}
                    />
                </label>
                {slugLocked && (
                    <p id="slug-locked" className="text-sm text-muted">
                        Locked while this newsletter is a public channel, because signup links use it.
                    </p>
                )}
                {form.errors.slug && <p className="text-sm text-danger">{form.errors.slug}</p>}
                <label className="block text-sm font-semibold" htmlFor="description">
                    Description
                    <textarea id="description" className="form-input mt-1" rows={4} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </label>
                {form.errors.description && <p className="text-sm text-danger">{form.errors.description}</p>}
                <fieldset className="space-y-3 border-t border-border pt-4">
                    <legend className="text-sm font-semibold">Sender</legend>
                    <label className="block text-sm font-semibold" htmlFor="sender_name">
                        Sender name
                        <input id="sender_name" className="form-input mt-1" value={form.data.sender_name} onChange={(e) => form.setData('sender_name', e.target.value)} />
                    </label>
                    {form.errors.sender_name && <p className="text-sm text-danger">{form.errors.sender_name}</p>}
                    <SenderSelect
                        value={form.data.sender_email}
                        original={newsletter?.sender_email ?? ''}
                        options={senderOptions}
                        reservedLocalParts={reservedLocalParts}
                        error={addressErrors.sender_email}
                        onChange={(value) => form.setData('sender_email', value)}
                    />
                    <AddressPicker
                        id="reply_to"
                        label="Reply-to email"
                        local={form.data.reply_local}
                        domain={form.data.reply_domain}
                        domains={replyDomains}
                        unavailableNote="not approved"
                        help={`Any enabled approved domain can be used for replies.${defaultReplyTo ? ` Leave blank to use the default reply-to, ${defaultReplyTo}.` : ''}`}
                        error={addressErrors.reply_to}
                        onLocalChange={(value) => form.setData('reply_local', value)}
                        onDomainChange={(value) => form.setData('reply_domain', value)}
                    />
                    {newsletter && newsletter.send_blocks.reasons.length > 0 && (
                        <div role="alert" className="rounded-xl border-l-4 border-[var(--color-sg-error)] bg-[var(--color-sg-error-mist)] p-3 text-sm text-danger">
                            <p className="font-semibold">Sending is blocked for this newsletter</p>
                            <ul className="mt-1 list-disc pl-5">
                                {newsletter.send_blocks.reasons.map((reason) => (
                                    <li key={reason}>{reason}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {newsletter?.send_blocks.last_blocked_at && (
                        <p className="text-sm text-muted">
                            Last blocked send: {new Date(newsletter.send_blocks.last_blocked_at).toLocaleString('en-GB')}
                        </p>
                    )}
                    {newsletter && (
                        <div className="rounded-xl border border-border bg-white/50 p-3 text-sm" aria-live="polite">
                            <p className="flex flex-wrap items-center gap-2">
                                <span
                                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-bold ${newsletter.sender_verification.verified ? 'bg-success-mist text-success' : 'bg-warning-mist text-warning'}`}
                                >
                                    {newsletter.sender_verification.verified ? 'Sender verified' : 'Sender not verified'}
                                </span>
                                {newsletter.sender_verification.checked_at && (
                                    <span className="text-muted">
                                        Checked {new Date(newsletter.sender_verification.checked_at).toLocaleString('en-GB')}
                                    </span>
                                )}
                            </p>
                            {newsletter.sender_verification.message && <p className="mt-1 text-body">{newsletter.sender_verification.message}</p>}
                            {!newsletter.sender_verification.verified && (
                                <p className="mt-1 text-muted">Emails from this newsletter are blocked until the sender is verified in Mailgun.</p>
                            )}
                            <button
                                type="button"
                                className="sg-btn sg-btn-secondary sg-btn-sm mt-2"
                                onClick={() => router.post(`/staff/newsletters/${newsletter.id}/verify-sender`, {}, { preserveScroll: true })}
                            >
                                Re-check sender
                            </button>
                        </div>
                    )}
                </fieldset>
                <label className="flex gap-2 text-sm" htmlFor="subscribe_on_signup">
                    <input
                        id="subscribe_on_signup"
                        type="checkbox"
                        checked={form.data.subscribe_on_signup}
                        onChange={(e) => form.setData('subscribe_on_signup', e.target.checked)}
                    />
                    Offer this newsletter when new members sign up (they tick to opt in)
                </label>
                <fieldset className="space-y-3 border-t border-border pt-4">
                    <legend className="text-sm font-semibold">Public channel</legend>
                    <p className="text-sm text-muted">
                        Link this newsletter to a channel page on the public site. Archived newsletters are never shown publicly.
                    </p>
                    <label className="block text-sm font-semibold" htmlFor="channel">
                        Channel
                        <select
                            id="channel"
                            className="form-input mt-1"
                            value={form.data.channel}
                            onChange={(e) => form.setData('channel', e.target.value)}
                        >
                            <option value="">Not a public channel</option>
                            {channelOptions.map((option) => (
                                <option key={option.value} value={option.value} disabled={option.taken_by !== null}>
                                    {option.label} (/{option.slug}){option.taken_by ? ` — linked to ${option.taken_by}` : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    {form.errors.channel && <p className="text-sm text-danger">{form.errors.channel}</p>}
                    {linkedChannel && (
                        <>
                            <label className="flex gap-2 text-sm" htmlFor="show_on_home">
                                <input
                                    id="show_on_home"
                                    type="checkbox"
                                    checked={form.data.show_on_home}
                                    onChange={(e) => form.setData('show_on_home', e.target.checked)}
                                />
                                Show on the home page
                            </label>
                            <label className="flex gap-2 text-sm" htmlFor="show_in_nav">
                                <input
                                    id="show_in_nav"
                                    type="checkbox"
                                    checked={form.data.show_in_nav}
                                    onChange={(e) => form.setData('show_in_nav', e.target.checked)}
                                />
                                Show in the navigation menu
                            </label>
                            <label className="block text-sm font-semibold" htmlFor="display_order">
                                Display order
                                <input
                                    id="display_order"
                                    type="number"
                                    min={0}
                                    max={999}
                                    className="form-input mt-1 !w-32"
                                    value={form.data.display_order}
                                    onChange={(e) => form.setData('display_order', Number(e.target.value))}
                                />
                            </label>
                            {form.errors.display_order && <p className="text-sm text-danger">{form.errors.display_order}</p>}
                            <label className="block text-sm font-semibold" htmlFor="public_label">
                                Public label
                                <input
                                    id="public_label"
                                    className="form-input mt-1"
                                    placeholder={form.data.name}
                                    value={form.data.public_label}
                                    onChange={(e) => form.setData('public_label', e.target.value)}
                                />
                            </label>
                            {form.errors.public_label && <p className="text-sm text-danger">{form.errors.public_label}</p>}
                            <label className="block text-sm font-semibold" htmlFor="public_description">
                                Public description
                                <textarea
                                    id="public_description"
                                    className="form-input mt-1"
                                    rows={3}
                                    placeholder={form.data.description}
                                    value={form.data.public_description}
                                    onChange={(e) => form.setData('public_description', e.target.value)}
                                />
                            </label>
                            {form.errors.public_description && <p className="text-sm text-danger">{form.errors.public_description}</p>}
                            <p className="text-sm text-muted">Leave label or description blank to use the newsletter name and description.</p>
                        </>
                    )}
                </fieldset>
                {newsletter?.status === 'archived' && (
                    <p className="text-sm text-muted">Archived: hidden from signup and cannot be sent to.</p>
                )}
                {newsletter?.legacy_list && (
                    <p className="text-sm text-muted">Linked channel list: {newsletter.legacy_list}. Channel signups keep using this list.</p>
                )}
                <div className="flex flex-wrap gap-2">
                    <button type="submit" className="button-primary" disabled={form.processing}>
                        Save
                    </button>
                    {newsletter && !newsletter.archived_at && (
                        <button type="button" className="sg-btn sg-btn-secondary" onClick={() => router.post(`/staff/newsletters/${newsletter.id}/archive`)}>
                            Archive
                        </button>
                    )}
                    {newsletter?.archived_at && (
                        <button type="button" className="sg-btn sg-btn-secondary" onClick={() => router.post(`/staff/newsletters/${newsletter.id}/restore`)}>
                            Restore
                        </button>
                    )}
                </div>
            </form>
            {newsletter && (
                <section aria-label="Segments" className="sg-card space-y-3 p-5 sm:p-6">
                    <h2 className="text-base font-semibold text-body">Segments</h2>
                    <ul className="text-sm">
                        {segments.map((segment) => (
                            <li key={segment.id}>
                                {segment.name} — also confirmed on {segment.also}
                            </li>
                        ))}
                    </ul>
                    {otherNewsletters.length > 0 && (
                        <form
                            className="space-y-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const data = new FormData(event.currentTarget);
                                router.post(`/staff/newsletters/${newsletter.id}/segments`, {
                                    name: String(data.get('name') ?? ''),
                                    also_newsletter_id: String(data.get('also_newsletter_id') ?? ''),
                                });
                            }}
                        >
                            <input name="name" placeholder="Segment name" className="form-input" required />
                            <select name="also_newsletter_id" className="form-input" required defaultValue="">
                                <option value="" disabled>
                                    Also confirmed on
                                </option>
                                {otherNewsletters.map((other) => (
                                    <option key={other.id} value={other.id}>
                                        {other.name}
                                    </option>
                                ))}
                            </select>
                            <button type="submit" className="sg-btn sg-btn-secondary">
                                Add segment
                            </button>
                        </form>
                    )}
                </section>
            )}
        </WorkspaceLayout>
    );
}
