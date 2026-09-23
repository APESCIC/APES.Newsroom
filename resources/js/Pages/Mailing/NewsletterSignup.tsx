import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import PublicLayout from '../../Components/Layout/PublicLayout';

type NewsletterInfo = { name: string; slug: string; description: string | null };

export default function NewsletterSignup({
    newsletter,
    embed,
}: {
    newsletter: NewsletterInfo;
    embed: boolean;
}) {
    const status = usePage().props.status as string | undefined;
    const { data, setData, post, processing, errors } = useForm<{ email: string }>({ email: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const query = embed ? '?embed=1' : '';
        post(`/newsletters/${newsletter.slug}/signup${query}`);
    };

    const form = (
        <main id="main-content" className={embed ? 'p-4' : 'mx-auto max-w-lg px-5 py-12 sm:px-6'}>
            <h1 className="text-2xl font-bold text-body">{newsletter.name}</h1>
            <p className="mt-2 text-sm text-muted">
                {newsletter.description ?? 'Subscribe to this newsletter.'} We email a confirmation link before sending any news.
            </p>
            {status === 'check-email' && (
                <p className="status-badge-success mt-4" role="status">
                    Check your email to confirm {newsletter.name}.
                </p>
            )}
            <form onSubmit={submit} className="mt-6 flex flex-col gap-4" noValidate={false}>
                <div>
                    <label htmlFor="email" className="text-sm font-bold text-body">
                        Email address
                    </label>
                    <input
                        id="email"
                        type="email"
                        autoComplete="email"
                        required
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        aria-invalid={errors.email ? true : undefined}
                        aria-describedby={errors.email ? 'email-error' : undefined}
                        className="form-input mt-1"
                    />
                    {errors.email && (
                        <p id="email-error" className="text-sm text-danger" role="alert">
                            {errors.email}
                        </p>
                    )}
                </div>
                <button type="submit" disabled={processing} className="button-primary w-fit">
                    Subscribe
                </button>
            </form>
        </main>
    );

    return (
        <>
            <Head title={`Subscribe to ${newsletter.name}`} />
            {embed ? form : <PublicLayout>{form}</PublicLayout>}
        </>
    );
}
