import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';

type ConfirmMethod = 'password' | 'email_code' | 'sso';

type ConfirmIdentityProps = {
    method: ConfirmMethod;
    status?: string | null;
};

function PasswordConfirm() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/account/confirm-identity/password', { onFinish: () => reset('password') });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <div>
                <label htmlFor="password" className="text-sm font-bold text-body">Password</label>
                <input
                    id="password"
                    type="password"
                    autoComplete="current-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    required
                    autoFocus
                    className="form-input mt-1"
                />
                {errors.password && <p className="text-sm text-danger">{errors.password}</p>}
            </div>
            <button type="submit" disabled={processing} className="button-primary">Confirm</button>
        </form>
    );
}

function EmailCodeConfirm({ codeSent }: { codeSent: boolean }) {
    const send = useForm({});
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/account/confirm-identity/code');
    };

    return (
        <div className="flex flex-col gap-4">
            {codeSent && (
                <p className="status-badge-success" role="status">We have emailed you a 6-digit code.</p>
            )}
            <button
                type="button"
                disabled={send.processing}
                onClick={() => send.post('/account/confirm-identity/code/send')}
                className="button-secondary w-fit"
            >
                {codeSent ? 'Send a new code' : 'Email me a code'}
            </button>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div>
                    <label htmlFor="code" className="text-sm font-bold text-body">Confirmation code</label>
                    <input
                        id="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxLength={6}
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                        required
                        className="form-input mt-1"
                    />
                    {errors.code && <p className="text-sm text-danger">{errors.code}</p>}
                </div>
                <button type="submit" disabled={processing} className="button-primary">Confirm</button>
            </form>
        </div>
    );
}

function SsoConfirm() {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;

    return (
        <div className="flex flex-col gap-4">
            {errors?.sso && <p className="text-sm text-danger">{errors.sso}</p>}
            <a href="/account/confirm-identity/sso" className="button-primary">Sign in to APES again</a>
        </div>
    );
}

function renderMethod(method: ConfirmMethod, codeSent: boolean) {
    switch (method) {
        case 'password':
            return <PasswordConfirm />;
        case 'email_code':
            return <EmailCodeConfirm codeSent={codeSent} />;
        case 'sso':
            return <SsoConfirm />;
        default: {
            const unreachable: never = method;
            return unreachable;
        }
    }
}

export default function ConfirmIdentity({ method, status }: ConfirmIdentityProps) {
    return (
        <>
            <Head title="Confirm it's you" />
            <AuthCard
                title="Confirm it's you"
                description="For your security, please confirm your identity before changing sensitive account settings."
            >
                {renderMethod(method, status === 'code-sent')}
                <p className="mt-6 text-sm">
                    <Link href="/account" className="text-teal-deep hover:underline">Cancel and return to your account</Link>
                </p>
            </AuthCard>
        </>
    );
}
