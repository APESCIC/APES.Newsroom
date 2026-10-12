import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { browserSupportsWebAuthn, startAuthentication } from '@simplewebauthn/browser';
import { FormEventHandler, useState } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';
import { requestPasskeyOptions, signInErrorMessage } from '../../Components/Auth/passkeys';

function PasskeyOption() {
    const { errors: pageErrors } = usePage<{ errors?: Record<string, string> }>().props;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const signInWithPasskey = async () => {
        setError(null);
        setBusy(true);

        try {
            const response = await requestPasskeyOptions('/two-factor-challenge/passkey/options');

            if (!response.ok) {
                setError('Could not start passkey sign-in. Please sign in again.');
                setBusy(false);
                return;
            }

            const credential = await startAuthentication({ optionsJSON: await response.json() });
            router.post('/two-factor-challenge/passkey', { credential: JSON.stringify(credential) }, { onFinish: () => setBusy(false) });
        } catch (caught) {
            setError(signInErrorMessage(caught));
            setBusy(false);
        }
    };

    const message = error ?? pageErrors?.passkey;

    return (
        <div className="mt-4 flex flex-col gap-2">
            <button type="button" onClick={signInWithPasskey} disabled={busy} className="button-secondary w-full">
                {busy ? 'Waiting for your passkey…' : 'Use a passkey'}
            </button>
            {message && <p className="text-sm text-danger">{message}</p>}
        </div>
    );
}

export default function TwoFactorChallenge({ has_passkeys = false }: { has_passkeys?: boolean }) {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const [supportsPasskeys] = useState(() => browserSupportsWebAuthn());
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/two-factor-challenge', { onFinish: () => reset('code', 'recovery_code') });
    };

    const switchMode = () => {
        reset('code', 'recovery_code');
        clearErrors();
        setUsingRecoveryCode(!usingRecoveryCode);
    };

    return (
        <>
            <Head title="Two-factor authentication" />
            <AuthCard
                title="Two-factor authentication"
                description={
                    usingRecoveryCode
                        ? 'Enter one of the recovery codes you saved when you turned on two-factor.'
                        : 'Enter the 6-digit code from your authenticator app to finish signing in.'
                }
            >
                <form onSubmit={submit} className="flex flex-col gap-4">
                    {usingRecoveryCode ? (
                        <div>
                            <label htmlFor="recovery_code" className="text-sm font-bold text-body">Recovery code</label>
                            <input
                                id="recovery_code"
                                autoComplete="off"
                                spellCheck={false}
                                maxLength={40}
                                value={data.recovery_code}
                                onChange={(e) => setData('recovery_code', e.target.value)}
                                required
                                autoFocus
                                className="form-input mt-1 font-mono"
                            />
                            {errors.recovery_code && <p className="text-sm text-danger">{errors.recovery_code}</p>}
                        </div>
                    ) : (
                        <div>
                            <label htmlFor="code" className="text-sm font-bold text-body">Authentication code</label>
                            <input
                                id="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                pattern="[0-9 ]*"
                                maxLength={7}
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                required
                                autoFocus
                                className="form-input mt-1"
                            />
                            {errors.code && <p className="text-sm text-danger">{errors.code}</p>}
                        </div>
                    )}
                    <button type="submit" disabled={processing} className="button-primary">
                        Continue
                    </button>
                </form>
                {has_passkeys && supportsPasskeys && <PasskeyOption />}
                <button type="button" onClick={switchMode} className="mt-4 text-sm text-teal-deep hover:underline">
                    {usingRecoveryCode ? 'Use your authenticator app instead' : 'Use a recovery code instead'}
                </button>
                <p className="mt-6 text-sm text-muted">
                    <Link href="/login" className="text-teal-deep hover:underline">Cancel and start again</Link>
                </p>
            </AuthCard>
        </>
    );
}
