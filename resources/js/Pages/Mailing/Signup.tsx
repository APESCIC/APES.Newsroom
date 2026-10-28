import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import PublicLayout from '../../Components/Layout/PublicLayout';

type ListOption = { value: string; label: string; purpose: string };

export default function Signup({ lists, status }: { lists: ListOption[]; status?: string }) {
    const { data, setData, post, processing, errors } = useForm<{ email: string; lists: string[] }>({
        email: '',
        lists: [],
    });

    const toggleList = (value: string) => {
        setData(
            'lists',
            data.lists.includes(value) ? data.lists.filter((l) => l !== value) : [...data.lists, value],
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/mailing/signup');
    };

    return (
        <PublicLayout>
            <Head title="Mailing lists" />
            <main id="main-content" className="mx-auto max-w-lg px-5 py-12 sm:px-6">
                <div className="form-panel">
                    <h1 className="text-2xl font-bold text-body">APES Newsroom mailing lists</h1>
                    <p className="mt-2 text-sm text-muted">
                        Choose which APES lists you want. Nothing is pre-selected. We will email you a confirmation link for
                        each list before sending any news.
                    </p>

                    {status === 'check-email' && (
                        <p className="status-badge-success mt-4">Check your email to confirm each list you selected.</p>
                    )}

                    <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
                        <div>
                            <label htmlFor="email" className="text-sm font-bold text-body">Email address</label>
                            <input
                                id="email"
                                type="email"
                                required
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="form-input mt-1"
                            />
                            {errors.email && <p className="text-sm text-danger">{errors.email}</p>}
                        </div>

                        <fieldset className="flex flex-col gap-3">
                            <legend className="text-sm font-bold text-body">Lists</legend>
                            {lists.map((list) => (
                                <label key={list.value} className="flex gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={data.lists.includes(list.value)}
                                        onChange={() => toggleList(list.value)}
                                    />
                                    <span>
                                        <span className="font-bold text-body">{list.label}</span>
                                        <span className="block text-muted">{list.purpose}</span>
                                    </span>
                                </label>
                            ))}
                            {errors.lists && <p className="text-sm text-danger">{errors.lists}</p>}
                        </fieldset>

                        <button type="submit" disabled={processing} className="button-primary w-fit">
                            Subscribe
                        </button>
                    </form>
                </div>
            </main>
        </PublicLayout>
    );
}
