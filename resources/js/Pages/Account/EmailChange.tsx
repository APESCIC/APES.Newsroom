import { Head, Link, router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';

type EmailChangeState = 'confirm' | 'cancel' | 'confirmed' | 'cancelled' | 'unavailable';

type EmailChangeProps = {
    state: EmailChangeState;
    new_email: string;
    action_url: string | null;
    message?: string | null;
};

function ActionButton({ url, label, danger = false }: { url: string; label: string; danger?: boolean }) {
    const [processing, setProcessing] = useState(false);

    return (
        <button
            type="button"
            disabled={processing}
            className={danger ? 'button-danger' : 'button-primary'}
            onClick={() => router.post(url, {}, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) })}
        >
            {label}
        </button>
    );
}

function content({ state, new_email, action_url, message }: EmailChangeProps): { title: string; body: ReactNode } {
    switch (state) {
        case 'confirm':
            return {
                title: 'Confirm your new email address',
                body: (
                    <>
                        <p className="text-sm text-body">Use {new_email} as the email address for your APES Newsroom account?</p>
                        {action_url && <ActionButton url={action_url} label="Confirm new email address" />}
                    </>
                ),
            };
        case 'cancel':
            return {
                title: 'Cancel email change',
                body: (
                    <>
                        <p className="text-sm text-body">
                            Stop the change to {new_email}? Your email address will stay as it is.
                        </p>
                        {action_url && <ActionButton url={action_url} label="Cancel this change" danger />}
                    </>
                ),
            };
        case 'confirmed':
            return {
                title: 'Email address changed',
                body: (
                    <>
                        <p className="text-sm text-body">Your account now uses {new_email}. Other sessions have been signed out.</p>
                        <Link href="/login" className="button-primary">Sign in</Link>
                    </>
                ),
            };
        case 'cancelled':
            return {
                title: 'Email change cancelled',
                body: (
                    <p className="text-sm text-body">
                        Your email address has not changed. If you did not ask for this change, sign in and change your
                        password from your account security page.
                    </p>
                ),
            };
        case 'unavailable':
            return {
                title: 'This link can no longer be used',
                body: (
                    <p className="text-sm text-body">
                        {message ?? 'This email change has already been confirmed, cancelled or has expired.'}
                    </p>
                ),
            };
        default: {
            const unreachable: never = state;
            return unreachable;
        }
    }
}

export default function EmailChange(props: EmailChangeProps) {
    const { title, body } = content(props);

    return (
        <>
            <Head title={title} />
            <AuthCard title={title}>
                <div className="flex flex-col gap-4">{body}</div>
            </AuthCard>
        </>
    );
}
