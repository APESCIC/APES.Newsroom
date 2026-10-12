import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';

export default function TwoFactorChallenge() {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
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
