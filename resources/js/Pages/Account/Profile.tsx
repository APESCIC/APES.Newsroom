import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
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
};

type ProfileProps = {
    user: ProfileUser;
    membership: MembershipSummary;
    status?: string;
    can_delete_account: boolean;
    deletion_block_reason: string | null;
};

export default function Profile({ user, membership, status, can_delete_account, deletion_block_reason }: ProfileProps) {
    const { data, setData, patch, processing, errors, delete: destroy } = useForm({
        name: user.name,
        email: user.email,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch('/account');
    };

    const deleteAccount = () => {
        if (confirm('Delete your account permanently? This cannot be undone.')) {
            destroy('/account');
        }
    };
    const deleteError = (errors as Record<string, string>).delete_account;

    return (
        <PublicLayout>
            <Head title="Your account" />
            <main id="main-content" className="mx-auto max-w-lg px-5 py-12 sm:px-6">
                <div className="glass-form-panel">
                    <h1 className="text-2xl font-bold text-body">Your account</h1>
                    <p className="mt-1 text-sm text-muted">Manage your profile and data.</p>

                    {status === 'profile-updated' && <p className="status-badge-success mt-4">Profile updated.</p>}
                    {status === 'email-verified' && <p className="status-badge-success mt-4">Email verified.</p>}

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

                <div className="glass-form-panel mt-6">
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
                </div>

                <div className="glass-form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Public profile</h2>
                    <Link href="/account/public-profile" className="mt-2 inline-block text-sm text-teal-deep hover:underline">
                        Edit public profile
                    </Link>
                </div>

                <div className="glass-form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Newsletters and mailing lists</h2>
                    <p className="mt-1 text-sm text-muted">
                        Confirm subscriptions separately; creating an account does not subscribe you automatically.
                    </p>
                    <Link href="/account/mailing" className="mt-2 inline-block text-sm text-teal-deep hover:underline">
                        Manage newsletter preferences
                    </Link>
                </div>

                <div className="glass-form-panel mt-6">
                    <h2 className="text-lg font-bold text-body">Your data</h2>
                    <Link href="/account/export" className="mt-2 inline-block text-sm text-teal-deep hover:underline">
                        Download account data (JSON)
                    </Link>
                </div>

                <div className="glass-form-panel mt-6">
                    <h2 className="text-lg font-bold text-danger">Danger zone</h2>
                    {can_delete_account ? (
                        <button type="button" onClick={deleteAccount} className="button-danger mt-2">
                            Delete account
                        </button>
                    ) : (
                        <p className="mt-2 text-sm text-muted">{deletion_block_reason}</p>
                    )}
                    {deleteError && <p className="text-sm text-danger">{deleteError}</p>}
                </div>

                <p className="mt-6 text-sm">
                    <Link href="/" className="link-on-glass hover:underline">Back to home</Link>
                </p>
            </main>
        </PublicLayout>
    );
}
