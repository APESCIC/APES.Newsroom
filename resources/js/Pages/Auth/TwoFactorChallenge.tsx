import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';

export default function TwoFactorChallenge() {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/two-factor-challenge', { onFinish: () => reset('code') });
    };

    return (
        <>
            <Head title="Two-factor authentication" />
            <AuthCard title="Two-factor authentication" description="Enter the 6-digit code from your authenticator app to finish signing in.">
                <form onSubmit={submit} className="flex flex-col gap-4">
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
                    <button type="submit" disabled={processing} className="button-primary">
                        Continue
                    </button>
                </form>
                <p className="mt-6 text-sm text-muted">
                    <Link href="/login" className="text-teal-deep hover:underline">Cancel and start again</Link>
                </p>
            </AuthCard>
        </>
    );
}
