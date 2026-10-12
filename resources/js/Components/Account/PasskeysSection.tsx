import { router, useForm, usePage } from '@inertiajs/react';
import { browserSupportsWebAuthn, startRegistration, WebAuthnError } from '@simplewebauthn/browser';
import { FormEvent, FormEventHandler, useState } from 'react';

export type AccountPasskey = {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
};

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value));
}

function registrationErrorMessage(error: unknown): string {
    if (error instanceof WebAuthnError && error.code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED') {
        return 'This device already has a passkey for your account.';
    }

    if (error instanceof Error && (error.name === 'NotAllowedError' || error.name === 'AbortError')) {
        return 'Passkey setup was cancelled or timed out.';
    }

    return 'Your browser could not create a passkey. Please try again.';
}

function AddPasskeyForm() {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        setBusy(true);

        try {
            const response = await fetch('/account/passkeys/options', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            });

            if (response.status === 423) {
                router.visit('/account/confirm-identity');
                return;
            }

            if (!response.ok) {
                const body = (await response.json().catch(() => null)) as { message?: string } | null;
                setError(body?.message ?? 'Could not start passkey setup. Please try again.');
                setBusy(false);
                return;
            }

            const credential = await startRegistration({ optionsJSON: await response.json() });

            router.post(
                '/account/passkeys',
                { name: name.trim(), credential: JSON.stringify(credential) },
                { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => setName('') },
            );
        } catch (caught) {
            setError(registrationErrorMessage(caught));
            setBusy(false);
        }
    };

    const message = error ?? errors?.passkey ?? errors?.name;

    return (
        <form onSubmit={submit} className="mt-4 flex flex-col gap-2">
            <label htmlFor="passkey_name" className="text-sm font-bold text-body">Name for this passkey</label>
            <input
                id="passkey_name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                maxLength={60}
                required
                placeholder="For example, Work laptop"
                className="form-input w-full max-w-sm"
            />
            {message && <p className="text-sm text-danger">{message}</p>}
            <button type="submit" disabled={busy || name.trim() === ''} className="button-primary w-fit">
                {busy ? 'Waiting for your device…' : 'Add a passkey'}
            </button>
        </form>
    );
}

function PasskeyRow({ passkey }: { passkey: AccountPasskey }) {
    const [renaming, setRenaming] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({ name: passkey.name });

    const rename: FormEventHandler = (e) => {
        e.preventDefault();
        patch(`/account/passkeys/${passkey.id}`, { preserveScroll: true, onSuccess: () => setRenaming(false) });
    };

    const remove = () => {
        if (confirm(`Remove the passkey "${passkey.name}"? You will no longer be able to sign in with it.`)) {
            router.delete(`/account/passkeys/${passkey.id}`, { preserveScroll: true });
        }
    };

    return (
        <li className="flex flex-col gap-2 border-b border-border py-3 last:border-b-0">
            {renaming ? (
                <form onSubmit={rename} className="flex flex-wrap items-end gap-2">
                    <div className="flex flex-col">
                        <label htmlFor={`passkey_${passkey.id}_name`} className="text-sm font-bold text-body">New name</label>
                        <input
                            id={`passkey_${passkey.id}_name`}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            maxLength={60}
                            required
                            className="form-input"
                        />
                    </div>
                    <button type="submit" disabled={processing} className="button-primary">Save</button>
                    <button type="button" className="button-secondary" onClick={() => setRenaming(false)}>Cancel</button>
                    {errors.name && <p className="w-full text-sm text-danger">{errors.name}</p>}
                </form>
            ) : (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p className="font-bold text-body">{passkey.name}</p>
                        <p className="text-sm text-muted">
                            {passkey.created_at ? `Added ${formatDate(passkey.created_at)}` : 'Added'}
                            {' · '}
                            {passkey.last_used_at ? `Last used ${formatDate(passkey.last_used_at)}` : 'Not used yet'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" className="button-secondary" onClick={() => setRenaming(true)} aria-label={`Rename ${passkey.name}`}>
                            Rename
                        </button>
                        <button type="button" className="button-secondary" onClick={remove} aria-label={`Remove ${passkey.name}`}>
                            Remove
                        </button>
                    </div>
                </div>
            )}
        </li>
    );
}

export default function PasskeysSection({ passkeys }: { passkeys: AccountPasskey[] }) {
    const supported = browserSupportsWebAuthn();

    return (
        <section aria-labelledby="passkeys-heading" className="mt-10">
            <h2 id="passkeys-heading" className="text-lg font-bold text-body">Passkeys</h2>
            <p className="mt-1 text-sm text-muted">
                Sign in with your fingerprint, face, screen lock or a security key instead of a password. Passkeys cannot be phished.
            </p>

            {passkeys.length > 0 ? (
                <ul className="mt-4" aria-label="Your passkeys">
                    {passkeys.map((passkey) => (
                        <PasskeyRow key={passkey.id} passkey={passkey} />
                    ))}
                </ul>
            ) : (
                <p className="mt-3 text-sm text-body">You have no passkeys yet.</p>
            )}

            {supported ? (
                <AddPasskeyForm />
            ) : (
                <p className="mt-4 text-sm text-muted">This browser does not support passkeys.</p>
            )}
        </section>
    );
}
