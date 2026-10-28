import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill,
    startAuthentication,
    WebAuthnAbortService,
    type AuthenticationResponseJSON,
} from '@simplewebauthn/browser';
import { FormEventHandler, useEffect, useRef, useState } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';
import { requestPasskeyOptions, signInErrorMessage } from '../../Components/Auth/passkeys';

interface LoginProps {
    staffLoginUrl: string | null;
    staffLoginLabel: string | null;
    rememberDays?: number;
    magicLinksEnabled?: boolean;
}

const DEV_ROLES = [
    { key: 'public', label: 'Public' },
    { key: 'staff', label: 'Staff' },
    { key: 'admin', label: 'Admin' },
    { key: 'super_admin', label: 'Super admin' },
] as const;

const PASSKEY_OPTIONS_URL = '/passkeys/login/options';

function submitPasskey(credential: AuthenticationResponseJSON, remember: boolean, onFinish: () => void): void {
    router.post('/passkeys/login', { credential: JSON.stringify(credential), remember }, { onFinish });
}

export default function Login({ staffLoginUrl, staffLoginLabel, rememberDays = 30, magicLinksEnabled = true }: LoginProps) {
    const { devTools, errors: pageErrors } = usePage<{ devTools?: boolean; errors?: Record<string, string> }>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });
    const emailError = errors.email ?? pageErrors?.email;
    const [supportsPasskeys] = useState(() => browserSupportsWebAuthn());
    const [passkeyBusy, setPasskeyBusy] = useState(false);
    const [passkeyError, setPasskeyError] = useState<string | null>(null);
    const remember = useRef(data.remember);

    useEffect(() => {
        remember.current = data.remember;
    }, [data.remember]);

    useEffect(() => {
        let active = true;

        void (async () => {
            if (!(await browserSupportsWebAuthnAutofill())) {
                return;
            }

            const response = await requestPasskeyOptions(PASSKEY_OPTIONS_URL);

            if (!response.ok || !active) {
                return;
            }

            try {
                const credential = await startAuthentication({ optionsJSON: await response.json(), useBrowserAutofill: true });

                if (active) {
                    setPasskeyBusy(true);
                    submitPasskey(credential, remember.current, () => setPasskeyBusy(false));
                }
            } catch {
                // Autofill was dismissed or replaced by another sign-in attempt.
            }
        })();

        return () => {
            active = false;
            WebAuthnAbortService.cancelCeremony();
        };
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    const signInWithPasskey = async () => {
        setPasskeyError(null);
        setPasskeyBusy(true);

        try {
            const response = await requestPasskeyOptions(PASSKEY_OPTIONS_URL);

            if (!response.ok) {
                setPasskeyError('Could not start passkey sign-in. Please try again.');
                setPasskeyBusy(false);
                return;
            }

            const credential = await startAuthentication({ optionsJSON: await response.json() });
            submitPasskey(credential, remember.current, () => setPasskeyBusy(false));
        } catch (caught) {
            setPasskeyError(signInErrorMessage(caught));
            setPasskeyBusy(false);
        }
    };

    const passkeyMessage = passkeyError ?? pageErrors?.passkey;

    return (
        <>
            <Head title="Log in" />
            <AuthCard title="Log in">
                {staffLoginUrl && (
                    <div className="mb-6 flex flex-col gap-2">
                        <a href={staffLoginUrl} className="button-secondary w-full">
                            {staffLoginLabel ?? 'Staff sign in'}
                        </a>
                        <p className="text-center text-xs text-muted">
                            Staff must use the button above. Public accounts can sign in below.
                        </p>
                    </div>
                )}
                {devTools && (
                    <div className="mb-6 flex flex-col gap-2 rounded-sg-field border border-warning/40 bg-warning-mist p-3">
                        <p className="text-xs font-bold tracking-wide text-warning uppercase">Local role preview</p>
                        <div className="flex flex-wrap gap-1.5">
                            {DEV_ROLES.map((role) => (
                                <button
                                    key={role.key}
                                    type="button"
                                    onClick={() => router.post(`/_dev/login/${role.key}`)}
                                    className="button-secondary px-2.5 py-1 text-xs"
                                >
                                    {role.label}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div>
                        <label htmlFor="email" className="text-sm font-bold text-body">Email</label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="username webauthn"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            autoFocus
                            className="form-input mt-1"
                        />
                        {emailError && <p className="text-sm text-danger">{emailError}</p>}
                    </div>
                    <div>
                        <label htmlFor="password" className="text-sm font-bold text-body">Password</label>
                        <input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            className="form-input mt-1"
                        />
                        {errors.password && <p className="text-sm text-danger">{errors.password}</p>}
                    </div>
                    <label className="flex items-center gap-2 text-sm text-body">
                        <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                        Keep me signed in on this device for {rememberDays} {rememberDays === 1 ? 'day' : 'days'}
                    </label>
                    <button type="submit" disabled={processing} className="button-primary">
                        Log in
                    </button>
                </form>
                {supportsPasskeys && (
                    <div className="mt-4 flex flex-col gap-2">
                        <button type="button" onClick={signInWithPasskey} disabled={passkeyBusy} className="button-secondary w-full">
                            {passkeyBusy ? 'Waiting for your passkey…' : 'Sign in with a passkey'}
                        </button>
                        {passkeyMessage && <p className="text-sm text-danger">{passkeyMessage}</p>}
                    </div>
                )}
                <p className="mt-6 flex flex-col gap-1 text-sm text-muted">
                    {magicLinksEnabled && (
                        <Link href="/login/magic-link" className="text-link underline">Email me a sign-in link instead</Link>
                    )}
                    <Link href="/forgot-password" className="text-link underline">Forgot your password?</Link>
                    <span>
                        Need an account? <Link href="/register" className="text-link underline">Register</Link>
                    </span>
                </p>
            </AuthCard>
        </>
    );
}
