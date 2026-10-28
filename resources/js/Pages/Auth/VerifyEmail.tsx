import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import AuthCard from '../../Components/Auth/AuthCard';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <>
            <Head title="Verify email" />
            <AuthCard
                title="Verify your email"
                description="Thanks for registering. Please check your email for a verification link before using your account."
            >
                {status === 'verification-link-sent' && (
                    <p className="status-badge-success mb-4">A new verification link has been sent to your email address.</p>
                )}
                <form onSubmit={submit}>
                    <button type="submit" disabled={processing} className="button-primary">
                        Resend verification email
                    </button>
                </form>
                <p className="mt-6 text-sm">
                    <Link href="/logout" method="post" as="button" className="text-link underline">
                        Log out
                    </Link>
                </p>
            </AuthCard>
        </>
    );
}
