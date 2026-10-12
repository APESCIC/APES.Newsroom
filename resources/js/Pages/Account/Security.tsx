import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import PasskeysSection, { type AccountPasskey } from '../../Components/Account/PasskeysSection';
import TwoFactorSection, { type TwoFactorState } from '../../Components/Account/TwoFactorSection';
import AccountLayout from '../../Components/Layout/AccountLayout';

export type AccountSession = {
    id: number;
    browser: string | null;
    platform: string | null;
    device_type: string | null;
    ip: string | null;
    last_active_at: string | null;
    remembered: boolean;
    current: boolean;
};

type SecurityProps = {
    sso_managed: boolean;
    has_password: boolean;
    status?: string | null;
    two_factor?: TwoFactorState | null;
    recovery_codes?: string[] | null;
    passkeys?: AccountPasskey[] | null;
    sessions?: AccountSession[];
};

const STATUS_MESSAGES: Record<string, string> = {
    'password-updated': 'Password updated. Other sessions have been signed out.',
    'two-factor-enabled': 'Two-factor authentication is on.',
    'two-factor-disabled': 'Two-factor authentication is off.',
    'recovery-codes-regenerated': 'New recovery codes created. Your old codes no longer work.',
    'recovery-code-used': 'You signed in with a recovery code. That code no longer works.',
    'passkey-added': 'Passkey added. You can now use it to sign in.',
    'passkey-renamed': 'Passkey renamed.',
    'passkey-removed': 'Passkey removed.',
    'session-ended': 'That session has been signed out.',
    'other-sessions-ended': 'All other sessions have been signed out.',
};

function describeDevice(session: AccountSession): string {
    const browser = session.browser ?? 'Unknown browser';

    return session.platform ? `${browser} on ${session.platform}` : browser;
}

function formatLastActive(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function SessionsSection({ sessions }: { sessions: AccountSession[] }) {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const hasOthers = sessions.some((session) => !session.current);

    return (
        <section aria-labelledby="sessions-heading" className="mt-10">
            <h2 id="sessions-heading" className="text-lg font-bold text-body">Where you're signed in</h2>
            <p className="mt-1 text-sm text-muted">
                If you don't recognise a session, sign it out and change your password.
            </p>
            {errors?.session && <p className="mt-2 text-sm text-danger">{errors.session}</p>}
            <ul className="mt-4 flex flex-col gap-3">
                {sessions.map((session) => (
                    <li key={session.id} className="flex flex-wrap items-start justify-between gap-3 rounded-control border border-border p-3">
                        <div>
                            <p className="text-sm font-bold text-body">
                                {describeDevice(session)}
                                {session.current && <span className="status-badge-success ml-2">This device</span>}
                            </p>
                            <p className="text-xs text-muted">
                                {[
                                    session.device_type ? session.device_type.charAt(0).toUpperCase() + session.device_type.slice(1) : null,
                                    session.ip ? `IP ${session.ip}` : null,
                                    `Last active ${formatLastActive(session.last_active_at)}`,
                                    session.remembered ? 'Remembered' : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>
                        {!session.current && (
                            <button
                                type="button"
                                className="button-secondary px-3 py-1 text-sm"
                                aria-label={`Sign out ${describeDevice(session)}`}
                                onClick={() => router.delete(`/account/sessions/${session.id}`, { preserveScroll: true })}
                            >
                                Sign out
                            </button>
                        )}
                    </li>
                ))}
            </ul>
            {hasOthers && (
                <button
                    type="button"
                    className="button-secondary mt-4"
                    onClick={() => router.delete('/account/sessions/others', { preserveScroll: true })}
                >
                    Sign out all other sessions
                </button>
            )}
        </section>
    );
}

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

export default function Security({ sso_managed, has_password, status, two_factor, recovery_codes, passkeys, sessions = [] }: SecurityProps) {
    const statusMessage = status ? STATUS_MESSAGES[status] : undefined;

    return (
        <AccountLayout
            title="Sign-in and security"
            description="Manage how you sign in to the APES Newsroom."
            backHref="/account"
            backLabel="← Your account"
        >
            <Head title="Sign-in and security" />

            {statusMessage && (
                <p className="status-badge-success mt-4" role="status">
                    {statusMessage}
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

            {!sso_managed && passkeys && <PasskeysSection passkeys={passkeys} />}

            {!sso_managed && two_factor && <TwoFactorSection state={two_factor} recoveryCodes={recovery_codes} />}

            <SessionsSection sessions={sessions} />
        </AccountLayout>
    );
}
