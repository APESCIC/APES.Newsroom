import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import PublicLayout from '../../Components/Layout/PublicLayout';

type Result = { title: string; slug: string; excerpt: string | null; published_at: string | null };

export default function SearchIndex({ query, results }: { query: string; results: Result[] }) {
    const { data, setData, get } = useForm({ q: query });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        get('/search', { preserveState: true });
    };

    return (
        <PublicLayout>
            <Head title="Search" />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <div className="form-panel">
                    <h1 className="text-2xl font-bold text-body">Search</h1>
                    <form onSubmit={submit} className="mt-4 flex gap-2">
                        <label htmlFor="q" className="sr-only">
                            Search
                        </label>
                        <input
                            id="q"
                            value={data.q}
                            onChange={(e) => setData('q', e.target.value)}
                            className="form-input flex-1"
                            placeholder="Search articles…"
                        />
                        <button type="submit" className="button-primary">
                            Search
                        </button>
                    </form>
                </div>
                <ul className="mt-8 space-y-4">
                    {results.map((r) => (
                        <li key={r.slug} className="sg-card p-5">
                            <Link href={`/articles/${r.slug}`} className="text-lg font-bold text-sg-ink hover:text-sg-teal hover:underline">
                                {r.title}
                            </Link>
                            {r.excerpt && <p className="mt-1 text-sm text-muted">{r.excerpt}</p>}
                        </li>
                    ))}
                </ul>
            </main>
        </PublicLayout>
    );
}
