import { Head, Link, useForm, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import ContactDetailsFields, { contactFormDefaults, type ContactDetailsValues } from '../../Components/Account/ContactDetailsFields';
import PublicLayout from '../../Components/Layout/PublicLayout';

type ProfileUser = {
    name: string;
    email: string;
    role: string;
    auth_provider: string | null;
};

type MembershipSummary = {
    status: string;
    status_label: string;
    is_paying: boolean;
    interval: string | null;
    current_period_end: string | null;
    has_stripe_customer: boolean;
};

type PlanOption = {
    slug: string;
    name: string;
    interval: string;
    amount_pence: number;
    currency: string;
};

type PendingEmail = {
    email: string;
    expires_at: string;
};

type MatchedMailingNotice = {
    newsletters: string[];
};

type ProfileProps = {
    user: ProfileUser;
    contact_details?: ContactDetailsValues | null;
    pending_email?: PendingEmail | null;
    membership: MembershipSummary;
    plans?: PlanOption[];
    stripe_enabled?: boolean;
    status?: string;
    can_delete_account: boolean;
    deletion_block_reason: string | null;
    matched_mailing_notice?: MatchedMailingNotice | null;
};

function formatMoney(amountPence: number, currency: string): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(amountPence / 100);
}

const EMAIL_STATUS_MESSAGES: Record<string, string> = {
    'email-change-requested': 'Check your new inbox: we have sent a link to confirm the change.',
    'email-change-resent': 'We have sent a new confirmation link.',
    'email-change-cancelled': 'Email change cancelled. Your email address has not changed.',
    'email-changed': 'Email address changed. Other sessions have been signed out.',
};

function ContactDetailsSection({ details, saved }: { details: ContactDetailsValues | null; saved: boolean }) {
    const { data, setData, patch, processing, errors } = useForm<ContactDetailsValues>(contactFormDefaults(details));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch('/account/contact-details', { preserveScroll: true });
    };

    return (
        <section className="form-panel mt-6" aria-labelledby="contact-details-heading">
            <h2 id="contact-details-heading" className="text-lg font-bold text-body">Contact details</h2>
            <p className="mt-1 text-sm text-muted">
                Optional. Only you and APES administrators can see these. They are never shown on your public profile.
            </p>
            {saved && <p className="status-badge-success mt-4" role="status">Contact details saved.</p>}
            <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                <ContactDetailsFields values={data} errors={errors} onChange={(field, value) => setData((current) => ({ ...current, [field]: value }))} />
                <button type="submit" disabled={processing} className="button-primary w-fit">
                    Save contact details
                </button>
            </form>
        </section>
    );
}

function PendingEmailNotice({ pending }: { pending: PendingEmail }) {
    return (
        <div className="mt-4 rounded-sg-field border border-warning/40 bg-warning-mist p-3 text-sm text-body" role="status">
            <p>
                Waiting for you to confirm <strong>{pending.email}</strong>. Your current email address stays in use until then.
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
                <button
                    type="button"
                    className="button-secondary px-3 py-1 text-sm"
                    onClick={() => router.post('/account/email/resend', {}, { preserveScroll: true })}
                >
                    Resend link
                </button>
                <button
                    type="button"
                    className="button-secondary px-3 py-1 text-sm"
                    onClick={() => router.delete('/account/email/pending', { preserveScroll: true })}
                >
                    Cancel change
                </button>
            </div>
        </div>
    );
}

export default function Profile({
    user,
    contact_details = null,
    pending_email = null,
    membership,
    plans = [],
    stripe_enabled = false,
    status,
    can_delete_account,
    deletion_block_reason,
    matched_mailing_notice = null,
}: ProfileProps) {
    const { data, setData, patch, processing, errors, reset, delete: destroy } = useForm({
        name: user.name,
        email: user.email,
        keep_newsletter_subscriptions: true as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // The address only changes once the new one is confirmed.
        patch('/account', { onSuccess: () => reset('email') });
    };

    const deleteAccount = () => {
        const keep = data.keep_newsletter_subscriptions;
        const message = keep
            ? 'Delete your account permanently? Your newsletter subscriptions will be kept.'
            : 'Delete your account permanently and remove your newsletter subscriptions? This cannot be undone.';
        if (confirm(message)) {
            destroy('/account');
        }
    };
    const deleteError = (errors as Record<string, string>).delete_account;
    const [offerCode, setOfferCode] = useState('');

    const startCheckout = (plan: string) => {
        router.post('/account/membership/checkout', {
            plan,
            offer_code: offerCode || undefined,
        });
    };

    const openPortal = () => {
        router.post('/account/membership/portal');
    };

    return (
        <PublicLayout>
            <Head title="Your account" />
            <main id="main-content" className="mx-auto max-w-lg px-5 py-12 sm:px-6">
                <div className="form-panel">
                    <h1 className="text-2xl font-bold text-body">Your account</h1>
                    <p className="mt-1 text-sm text-muted">Manage your profile and data.</p>

                    {status === 'profile-updated' && <p className="status-badge-success mt-4">Profile updated.</p>}
                    {status === 'email-verified' && <p className="status-badge-success mt-4">Email verified.</p>}

                    {matched_mailing_notice && (
                        <div className="mt-4 rounded-sg-field border border-sg-teal/40 bg-sg-info-mist p-3 text-sm text-body" role="status">
                            <p className="font-semibold">We found your existing newsletter subscriptions</p>
                            <ul className="mt-2 list-disc pl-5">
                                {matched_mailing_notice.newsletters.map((name) => (
                                    <li key={name}>{name}</li>
                                ))}
                            </ul>
                            <div className="mt-2 flex flex-wrap gap-3">
                                <Link href="/account/mailing" className="text-link underline">
                                    Manage preferences
                                </Link>
                                <button
                                    type="button"
                                    className="text-muted hover:underline"
                                    onClick={() => router.post('/account/mailing/matched-notice/dismiss', {}, { preserveScroll: true })}
                                >
                                    Dismiss
                                </button>
                            </div>
                        </div>
                    )}
                    {status && EMAIL_STATUS_MESSAGES[status] && (
                        <p className="status-badge-success mt-4" role="status">{EMAIL_STATUS_MESSAGES[status]}</p>
                    )}
                    {pending_email && <PendingEmailNotice pending={pending_email} />}
                    {status === 'membership-checkout-started' && (
                        <p className="status-badge-success mt-4" role="status">
                            Checkout completed. Your membership status updates when Stripe confirms payment.
                        </p>
                    )}

                    <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
                        <div>
                            <label htmlFor="name" className="text-sm font-bold text-body">Name</label>
                            <input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required className="form-input mt-1" />
                            {errors.name && <p className="text-sm text-danger">{errors.name}</p>}
                        </div>
                        <div>
                            <label htmlFor="email" className="text-sm font-bold text-body">Email</label>
                            <input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                disabled={user.auth_provider === 'cloudron_oidc'}
                                className="form-input mt-1"
                            />
                            {errors.email && <p className="text-sm text-danger">{errors.email}</p>}
                        </div>
                        <button type="submit" disabled={processing} className="button-primary w-fit">
                            Save changes
                        </button>
                    </form>
                </div>

                <ContactDetailsSection details={contact_details} saved={status === 'contact-details-updated'} />

                <div className="form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Sign-in and security</h2>
                    <p className="mt-1 text-sm text-muted">Change your password and review where you are signed in.</p>
                    <Link href="/account/security" className="mt-2 inline-block text-sm text-link underline">
                        Manage sign-in and security
                    </Link>
                </div>

                <div className="form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Membership</h2>
                    <p className="mt-2 text-sm text-body">{membership.status_label}</p>
                    {membership.is_paying && membership.interval ? (
                        <p className="mt-1 text-sm text-muted">
                            Billed {membership.interval}
                            {membership.current_period_end
                                ? ` · renews ${new Date(membership.current_period_end).toLocaleDateString('en-GB')}`
                                : ''}
                        </p>
                    ) : (
                        <p className="mt-1 text-sm text-muted">
                            Free membership includes your account and newsletter preferences. Paid tiers unlock gated posts when available.
                        </p>
                    )}

                    {stripe_enabled && !membership.is_paying && plans.length > 0 && (
                        <div className="mt-4 flex flex-col gap-3">
                            <label className="text-sm font-bold text-body">
                                Offer code (optional)
                                <input
                                    id="offer_code"
                                    className="form-input mt-1"
                                    value={offerCode}
                                    onChange={(e) => setOfferCode(e.target.value)}
                                    autoComplete="off"
                                />
                            </label>
                            <ul className="flex flex-col gap-3">
                                {plans.map((plan) => (
                                    <li key={plan.slug} className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-bold text-body">{plan.name}</p>
                                            <p className="text-sm text-muted">
                                                {formatMoney(plan.amount_pence, plan.currency)} / {plan.interval}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            className="button-primary shrink-0"
                                            onClick={() => startCheckout(plan.slug)}
                                        >
                                            Subscribe
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {membership.has_stripe_customer && (
                        <button type="button" className="button-secondary mt-4" onClick={openPortal}>
                            Manage billing
                        </button>
                    )}
                </div>

                <div className="form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Public profile</h2>
                    <Link href="/account/public-profile" className="mt-2 inline-block text-sm text-link underline">
                        Edit public profile
                    </Link>
                </div>

                <div className="form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Newsletters and mailing lists</h2>
                    <p className="mt-1 text-sm text-muted">
                        Confirm subscriptions separately; creating an account does not subscribe you automatically.
                    </p>
                    <Link href="/account/mailing" className="mt-2 inline-block text-sm text-link underline">
                        Manage newsletter preferences
                    </Link>
                </div>

                <div className="form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Your data</h2>
                    <Link href="/account/export" className="mt-2 inline-block text-sm text-link underline">
                        Download account data (JSON)
                    </Link>
                </div>

                <div className="form-panel mt-6">
                    <h2 className="text-lg font-bold text-danger">Danger zone</h2>
                    {can_delete_account ? (
                        <>
                            <label className="mt-3 flex items-start gap-2 text-sm text-body">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={data.keep_newsletter_subscriptions}
                                    onChange={(e) => setData('keep_newsletter_subscriptions', e.target.checked)}
                                />
                                <span>
                                    Keep my newsletter subscriptions
                                    <span className="block text-muted">
                                        Checked by default. Uncheck to remove your mailing contact and list memberships when the account is deleted.
                                    </span>
                                </span>
                            </label>
                            <button type="button" onClick={deleteAccount} className="button-danger mt-3">
                                Delete account
                            </button>
                        </>
                    ) : (
                        <p className="mt-2 text-sm text-muted">{deletion_block_reason}</p>
                    )}
                    {deleteError && <p className="text-sm text-danger">{deleteError}</p>}
                </div>

                <p className="mt-6 text-sm">
                    <Link href="/" className="text-link hover:underline">Back to home</Link>
                </p>
            </main>
        </PublicLayout>
    );
}
