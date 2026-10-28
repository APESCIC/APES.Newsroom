import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

export default function Confirmed({ list }: { list: string }) {
    return (
        <PublicLayout>
            <Head title="Subscription confirmed" />
            <main id="main-content" className="mx-auto max-w-lg px-5 py-12 sm:px-6">
                <div className="form-panel sm:p-8">
                    <p className="sg-badge sg-badge-ok">Confirmed</p>
                    <h1 className="mt-3 text-2xl font-bold text-body">Subscription confirmed</h1>
                    <p className="mt-2 text-muted">You are confirmed for {list}.</p>
                    <p className="mt-6 text-sm">
                        <Link href="/" className="text-link underline">
                            Back to home
                        </Link>
                    </p>
                </div>
            </main>
        </PublicLayout>
    );
}
