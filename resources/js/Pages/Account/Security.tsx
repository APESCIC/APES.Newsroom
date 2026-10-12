import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import AccountLayout from '../../Components/Layout/AccountLayout';

type SecurityProps = {
    sso_managed: boolean;
    has_password: boolean;
    status?: string | null;
};

function PasswordSection({ hasPassword }: { hasPassword: boolean }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/account/password', {
            preserveScroll: true,
            onFinish: () => reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <section aria-labelledby="password-heading" className="mt-8">
            <h2 id="password-heading" className="text-lg font-bold text-body">
                {hasPassword ? 'Change password' : 'Set a password'}
            </h2>
            <p className="mt-1 text-sm text-muted">
                Use at least 12 characters. Changing your password signs you out everywhere else.
            </p>
            <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                {hasPassword && (
                    <div>
                        <label htmlFor="current_password" className="text-sm font-bold text-body">Current password</label>
                        <input
                            id="current_password"
                            type="password"
                            autoComplete="current-password"
                            value={data.current_password}
                            onChange={(e) => setData('current_password', e.target.value)}
                            required
                            className="form-input mt-1"
                        />
                        {errors.current_password && <p className="text-sm text-danger">{errors.current_password}</p>}
                    </div>
                )}
                <div>
                    <label htmlFor="password" className="text-sm font-bold text-body">New password</label>
                    <input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        required
                        minLength={12}
                        className="form-input mt-1"
                    />
                    {errors.password && <p className="text-sm text-danger">{errors.password}</p>}
                </div>
                <div>
                    <label htmlFor="password_confirmation" className="text-sm font-bold text-body">Confirm new password</label>
                    <input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        required
                        className="form-input mt-1"
                    />
                </div>
                <button type="submit" disabled={processing} className="button-primary w-fit">
                    {hasPassword ? 'Change password' : 'Set password'}
                </button>
            </form>
        </section>
    );
}

export default function Security({ sso_managed, has_password, status }: SecurityProps) {
    return (
        <AccountLayout
            title="Sign-in and security"
            description="Manage how you sign in to the APES Newsroom."
            backHref="/account"
            backLabel="← Your account"
        >
            <Head title="Sign-in and security" />

            {status === 'password-updated' && (
                <p className="status-badge-success mt-4" role="status">
                    Password updated. Other sessions have been signed out.
                </p>
            )}

            {sso_managed ? (
                <section aria-labelledby="password-heading" className="mt-8">
                    <h2 id="password-heading" className="text-lg font-bold text-body">Password</h2>
                    <p className="mt-1 text-sm text-muted">
                        You sign in with APES staff sign-in. Manage your password and two-factor settings there.
                    </p>
                </section>
            ) : (
                <PasswordSection hasPassword={has_password} />
            )}
        </AccountLayout>
    );
}
