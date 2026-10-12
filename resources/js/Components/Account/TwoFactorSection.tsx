import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export type TwoFactorState = {
    enabled: boolean;
    recovery_codes_remaining: number;
    setup: { qr_image: string; secret: string } | null;
};

function formatSecret(secret: string): string {
    return secret.replace(/(.{4})/g, '$1 ').trim();
}

function downloadRecoveryCodes(codes: string[]): void {
    const contents = [
        'APES Newsroom recovery codes',
        'Each code can be used once if you cannot use your authenticator app.',
        '',
        ...codes,
        '',
    ].join('\n');
    const url = URL.createObjectURL(new Blob([contents], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'apes-newsroom-recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(url);
}

export function RecoveryCodesPanel({ codes }: { codes: string[] }) {
    return (
        <div className="mt-4 rounded-control border border-border p-4" role="region" aria-labelledby="recovery-codes-heading">
            <h3 id="recovery-codes-heading" className="text-base font-bold text-body">Save your recovery codes</h3>
            <p className="mt-1 text-sm text-muted">
                Use one of these if you lose your authenticator app. Each code works once. This is the only time they are shown.
            </p>
            <ul className="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-body" aria-label="Recovery codes">
                {codes.map((code) => (
                    <li key={code}>{code}</li>
                ))}
            </ul>
            <button type="button" className="button-secondary mt-4" onClick={() => downloadRecoveryCodes(codes)}>
                Download codes
            </button>
        </div>
    );
}

function SetupForm({ setup }: { setup: NonNullable<TwoFactorState['setup']> }) {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/account/two-factor/confirm', { preserveScroll: true, onFinish: () => reset('code') });
    };

    return (
        <div className="mt-4 flex flex-col gap-4">
            <ol className="list-decimal pl-5 text-sm text-body">
                <li>Scan this QR code with an authenticator app, or enter the key by hand.</li>
                <li>Enter the 6-digit code the app shows.</li>
            </ol>
            <img src={setup.qr_image} alt="QR code for your authenticator app" width={192} height={192} className="rounded-control bg-white p-2" />
            <p className="text-sm text-muted">
                Key: <code className="font-mono text-body">{formatSecret(setup.secret)}</code>
            </p>
            <form onSubmit={submit} className="flex flex-col gap-2">
                <label htmlFor="two_factor_code" className="text-sm font-bold text-body">Code from your app</label>
                <input
                    id="two_factor_code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9 ]*"
                    maxLength={7}
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value)}
                    required
                    className="form-input w-40"
                />
                {errors.code && <p className="text-sm text-danger">{errors.code}</p>}
                <button type="submit" disabled={processing} className="button-primary w-fit">
                    Turn on two-factor
                </button>
            </form>
        </div>
    );
}

export default function TwoFactorSection({ state, recoveryCodes }: { state: TwoFactorState; recoveryCodes?: string[] | null }) {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;

    return (
        <section aria-labelledby="two-factor-heading" className="mt-10">
            <h2 id="two-factor-heading" className="text-lg font-bold text-body">Two-factor authentication</h2>
            <p className="mt-1 text-sm text-muted">
                {state.enabled
                    ? 'On. You are asked for a code from your authenticator app after you sign in.'
                    : 'Add a second step to signing in with a code from an authenticator app.'}
            </p>
            {errors?.two_factor && <p className="mt-2 text-sm text-danger">{errors.two_factor}</p>}

            {recoveryCodes && recoveryCodes.length > 0 && <RecoveryCodesPanel codes={recoveryCodes} />}

            {state.enabled && (
                <p className="mt-3 text-sm text-body">
                    {state.recovery_codes_remaining} recovery {state.recovery_codes_remaining === 1 ? 'code' : 'codes'} left.
                </p>
            )}

            {!state.enabled && state.setup && <SetupForm setup={state.setup} />}

            {!state.enabled && !state.setup && (
                <button
                    type="button"
                    className="button-primary mt-4"
                    onClick={() => router.post('/account/two-factor', {}, { preserveScroll: true })}
                >
                    Set up two-factor
                </button>
            )}
        </section>
    );
}
