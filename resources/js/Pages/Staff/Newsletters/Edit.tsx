import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

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
    channel: string | null;
    show_on_home: boolean;
    show_in_nav: boolean;
    display_order: number;
    public_label: string | null;
    public_description: string | null;
};

type ChannelOption = { value: string; label: string; slug: string; taken_by: string | null };

export default function NewsletterEdit({
    newsletter,
    segments = [],
    otherNewsletters = [],
    channelOptions = [],
}: {
    newsletter: NewsletterData | null;
    segments?: Array<{ id: number; name: string; also: string }>;
    otherNewsletters?: Array<{ id: number; name: string }>;
    channelOptions?: ChannelOption[];
}) {
    const form = useForm({
        name: newsletter?.name ?? '',
        slug: newsletter?.slug ?? '',
        description: newsletter?.description ?? '',
        sender_name: newsletter?.sender_name ?? '',
        sender_email: newsletter?.sender_email ?? '',
        reply_to: newsletter?.reply_to ?? '',
        subscribe_on_signup: newsletter?.subscribe_on_signup ?? false,
        channel: newsletter?.channel ?? '',
        show_on_home: newsletter?.show_on_home ?? false,
        show_in_nav: newsletter?.show_in_nav ?? false,
        display_order: newsletter?.display_order ?? 0,
        public_label: newsletter?.public_label ?? '',
        public_description: newsletter?.public_description ?? '',
    });
    const slugLocked = Boolean(newsletter?.channel);
    const linkedChannel = channelOptions.find((option) => option.value === form.data.channel);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (newsletter) {
            form.patch(`/staff/newsletters/${newsletter.id}`);
        } else {
            form.post('/staff/newsletters');
        }
    };

    return (
        <WorkspaceLayout area="Staff" active="newsletters" title={newsletter ? 'Edit newsletter' : 'New newsletter'}>
            <Head title={newsletter ? newsletter.name : 'New newsletter'} />
            <p className="mb-4 text-sm">
                <Link href="/staff/newsletters" className="text-teal-deep hover:underline">
                    All newsletters
                </Link>
            </p>
            <form onSubmit={submit} className="max-w-xl space-y-4 rounded-lg border border-neutral-200 bg-white p-4">
                <label className="block text-sm font-semibold" htmlFor="name">
                    Name
                    <input id="name" className="mt-1 w-full rounded border px-3 py-2" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </label>
                {form.errors.name && <p className="text-sm text-red-700">{form.errors.name}</p>}
                <label className="block text-sm font-semibold" htmlFor="slug">
                    Slug
                    <input
                        id="slug"
                        className="mt-1 w-full rounded border px-3 py-2 read-only:bg-neutral-100"
                        value={form.data.slug}
                        readOnly={slugLocked}
                        aria-describedby={slugLocked ? 'slug-locked' : undefined}
                        onChange={(e) => form.setData('slug', e.target.value)}
                    />
                </label>
                {slugLocked && (
                    <p id="slug-locked" className="text-sm text-neutral-600">
                        Locked while this newsletter is a public channel, because signup links use it.
                    </p>
                )}
                {form.errors.slug && <p className="text-sm text-red-700">{form.errors.slug}</p>}
                <label className="block text-sm font-semibold" htmlFor="description">
                    Description
                    <textarea id="description" className="mt-1 w-full rounded border px-3 py-2" rows={4} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </label>
                {form.errors.description && <p className="text-sm text-red-700">{form.errors.description}</p>}
                <fieldset className="space-y-3 border-t border-neutral-200 pt-4">
                    <legend className="text-sm font-semibold">Sender</legend>
                    <label className="block text-sm font-semibold" htmlFor="sender_name">
                        Sender name
                        <input id="sender_name" className="mt-1 w-full rounded border px-3 py-2" value={form.data.sender_name} onChange={(e) => form.setData('sender_name', e.target.value)} />
                    </label>
                    {form.errors.sender_name && <p className="text-sm text-red-700">{form.errors.sender_name}</p>}
                    <label className="block text-sm font-semibold" htmlFor="sender_email">
                        Sender email
                        <input id="sender_email" type="email" className="mt-1 w-full rounded border px-3 py-2" value={form.data.sender_email} onChange={(e) => form.setData('sender_email', e.target.value)} />
                    </label>
                    {form.errors.sender_email && <p className="text-sm text-red-700">{form.errors.sender_email}</p>}
                    <label className="block text-sm font-semibold" htmlFor="reply_to">
                        Reply-to email
                        <input id="reply_to" type="email" className="mt-1 w-full rounded border px-3 py-2" value={form.data.reply_to} onChange={(e) => form.setData('reply_to', e.target.value)} />
                    </label>
                    {form.errors.reply_to && <p className="text-sm text-red-700">{form.errors.reply_to}</p>}
                    {newsletter && (
                        <div className="rounded border border-neutral-200 p-3 text-sm" aria-live="polite">
                            <p className="flex flex-wrap items-center gap-2">
                                <span
                                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-bold ${newsletter.sender_verification.verified ? 'bg-success-mist text-success' : 'bg-warning-mist text-warning'}`}
                                >
                                    {newsletter.sender_verification.verified ? 'Sender verified' : 'Sender not verified'}
                                </span>
                                {newsletter.sender_verification.checked_at && (
                                    <span className="text-neutral-600">
                                        Checked {new Date(newsletter.sender_verification.checked_at).toLocaleString('en-GB')}
                                    </span>
                                )}
                            </p>
                            {newsletter.sender_verification.message && <p className="mt-1 text-neutral-700">{newsletter.sender_verification.message}</p>}
                            {!newsletter.sender_verification.verified && (
                                <p className="mt-1 text-neutral-600">Emails from this newsletter are blocked until the sender is verified in Mailgun.</p>
                            )}
                            <button
                                type="button"
                                className="mt-2 rounded border px-3 py-1 text-sm"
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
                <fieldset className="space-y-3 border-t border-neutral-200 pt-4">
                    <legend className="text-sm font-semibold">Public channel</legend>
                    <p className="text-sm text-neutral-600">
                        Link this newsletter to a channel page on the public site. Archived newsletters are never shown publicly.
                    </p>
                    <label className="block text-sm font-semibold" htmlFor="channel">
                        Channel
                        <select
                            id="channel"
                            className="mt-1 w-full rounded border px-3 py-2"
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
                    {form.errors.channel && <p className="text-sm text-red-700">{form.errors.channel}</p>}
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
                                    className="mt-1 w-32 rounded border px-3 py-2"
                                    value={form.data.display_order}
                                    onChange={(e) => form.setData('display_order', Number(e.target.value))}
                                />
                            </label>
                            {form.errors.display_order && <p className="text-sm text-red-700">{form.errors.display_order}</p>}
                            <label className="block text-sm font-semibold" htmlFor="public_label">
                                Public label
                                <input
                                    id="public_label"
                                    className="mt-1 w-full rounded border px-3 py-2"
                                    placeholder={form.data.name}
                                    value={form.data.public_label}
                                    onChange={(e) => form.setData('public_label', e.target.value)}
                                />
                            </label>
                            {form.errors.public_label && <p className="text-sm text-red-700">{form.errors.public_label}</p>}
                            <label className="block text-sm font-semibold" htmlFor="public_description">
                                Public description
                                <textarea
                                    id="public_description"
                                    className="mt-1 w-full rounded border px-3 py-2"
                                    rows={3}
                                    placeholder={form.data.description}
                                    value={form.data.public_description}
                                    onChange={(e) => form.setData('public_description', e.target.value)}
                                />
                            </label>
                            {form.errors.public_description && <p className="text-sm text-red-700">{form.errors.public_description}</p>}
                            <p className="text-sm text-neutral-600">Leave label or description blank to use the newsletter name and description.</p>
                        </>
                    )}
                </fieldset>
                {newsletter?.status === 'archived' && (
                    <p className="text-sm text-neutral-600">Archived: hidden from signup and cannot be sent to.</p>
                )}
                {newsletter?.legacy_list && (
                    <p className="text-sm text-neutral-600">Linked channel list: {newsletter.legacy_list}. Channel signups keep using this list.</p>
                )}
                <div className="flex flex-wrap gap-2">
                    <button type="submit" className="button-primary" disabled={form.processing}>
                        Save
                    </button>
                    {newsletter && !newsletter.archived_at && (
                        <button type="button" className="rounded border px-3 py-2 text-sm" onClick={() => router.post(`/staff/newsletters/${newsletter.id}/archive`)}>
                            Archive
                        </button>
                    )}
                    {newsletter?.archived_at && (
                        <button type="button" className="rounded border px-3 py-2 text-sm" onClick={() => router.post(`/staff/newsletters/${newsletter.id}/restore`)}>
                            Restore
                        </button>
                    )}
                </div>
            </form>
            {newsletter && (
                <section className="mt-6 max-w-xl space-y-3">
                    <h2 className="font-semibold">Segments</h2>
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
                            <input name="name" placeholder="Segment name" className="w-full rounded border px-3 py-2" required />
                            <select name="also_newsletter_id" className="w-full rounded border px-3 py-2" required defaultValue="">
                                <option value="" disabled>
                                    Also confirmed on
                                </option>
                                {otherNewsletters.map((other) => (
                                    <option key={other.id} value={other.id}>
                                        {other.name}
                                    </option>
                                ))}
                            </select>
                            <button type="submit" className="rounded border px-3 py-2 text-sm">
                                Add segment
                            </button>
                        </form>
                    )}
                </section>
            )}
        </WorkspaceLayout>
    );
}
