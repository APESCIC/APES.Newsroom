import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import AccountLayout from '../../Components/Layout/AccountLayout';

type ListState = { list: string; label: string; purpose: string; status: string | null };
type NewsletterState = { id: number; name: string; description: string | null; status: string | null };
type MatchedMailingNotice = { newsletters: string[] };

export default function Preferences({
    email,
    lists,
    newsletters = [],
    signed,
    status,
    matched_mailing_notice = null,
}: {
    email: string;
    lists: ListState[];
    newsletters?: NewsletterState[];
    signed: boolean;
    status?: string;
    matched_mailing_notice?: MatchedMailingNotice | null;
}) {
    const initial = lists.filter((l) => l.status === 'confirmed' || l.status === 'pending').map((l) => l.list);
    const initialNewsletters = newsletters.filter((n) => n.status === 'confirmed' || n.status === 'pending').map((n) => n.id);

    const { data, setData, post, processing, errors } = useForm<{ lists: string[]; newsletter_ids: number[] }>({
        lists: initial,
        newsletter_ids: initialNewsletters,
    });

    const toggleList = (value: string) => {
        setData(
            'lists',
            data.lists.includes(value) ? data.lists.filter((l) => l !== value) : [...data.lists, value],
        );
    };

    const toggleNewsletter = (id: number) => {
        setData(
            'newsletter_ids',
            data.newsletter_ids.includes(id) ? data.newsletter_ids.filter((n) => n !== id) : [...data.newsletter_ids, id],
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(signed ? window.location.pathname + window.location.search : '/account/mailing');
    };

    return (
        <>
            <Head title="Mailing preferences" />
            <AccountLayout
                title="Mailing preferences"
                description={`Manage lists for ${email}. New lists need email confirmation.`}
                backHref={signed ? undefined : '/account'}
                backLabel="← Account"
            >
                {status === 'preferences-updated' && (
                    <p className="status-badge-success mt-4">Preferences saved. Confirm any new lists via email.</p>
                )}

                {matched_mailing_notice && (
                    <div className="mt-4 rounded-sg-field border border-sg-teal/40 bg-sg-info-mist p-3 text-sm text-body" role="status">
                        <p className="font-semibold">We found your existing newsletter subscriptions</p>
                        <ul className="mt-2 list-disc pl-5">
                            {matched_mailing_notice.newsletters.map((name) => (
                                <li key={name}>{name}</li>
                            ))}
                        </ul>
                        <p className="mt-2 text-muted">You can change them below, then save.</p>
                        <button
                            type="button"
                            className="mt-2 text-muted hover:underline"
                            onClick={() => router.post('/account/mailing/matched-notice/dismiss', {}, { preserveScroll: true })}
                        >
                            Dismiss
                        </button>
                    </div>
                )}

                <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
                    <fieldset className="flex flex-col gap-3">
                        <legend className="text-sm font-bold text-body">Lists</legend>
                        {lists.map((list) => (
                            <label key={list.list} className="flex gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.lists.includes(list.list)}
                                    onChange={() => toggleList(list.list)}
                                />
                                <span>
                                    <span className="font-bold text-body">{list.label}</span>
                                    {list.status && (
                                        <span className="ml-2 text-xs text-muted">({list.status})</span>
                                    )}
                                    <span className="block text-muted">{list.purpose}</span>
                                </span>
                            </label>
                        ))}
                        {errors.lists && <p className="text-sm text-danger">{errors.lists}</p>}
                    </fieldset>
                    {newsletters.length > 0 && (
                        <fieldset className="flex flex-col gap-3">
                            <legend className="text-sm font-bold text-body">Newsletters</legend>
                            {newsletters.map((newsletter) => (
                                <label key={newsletter.id} className="flex gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={data.newsletter_ids.includes(newsletter.id)}
                                        onChange={() => toggleNewsletter(newsletter.id)}
                                    />
                                    <span>
                                        <span className="font-bold text-body">{newsletter.name}</span>
                                        {newsletter.status && <span className="ml-2 text-xs text-muted">({newsletter.status})</span>}
                                        {newsletter.description && <span className="block text-muted">{newsletter.description}</span>}
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <button type="submit" disabled={processing} className="button-primary w-fit">
                        Save preferences
                    </button>
                </form>
            </AccountLayout>
        </>
    );
}
