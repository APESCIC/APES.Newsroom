import { Head, Link } from '@inertiajs/react';
import ApesLogo from '../../Components/Brand/ApesLogo';
import LineIcon from '../../Components/Icons/LineIcon';

export default function ErrorShow({
    status,
    title,
    message,
}: {
    status: number;
    title: string;
    message: string;
}) {
    return (
        <div className="public-shell flex flex-col">
            <Head title={title} />
            <header className="public-header px-5 py-3 sm:px-6">
                <div className="mx-auto max-w-public">
                    <Link href="/" className="inline-flex rounded-control">
                        <ApesLogo variant="masthead" className="h-12 w-auto object-contain" alt="APES Newsroom home" />
                    </Link>
                </div>
            </header>
            <main id="main-content" className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-5 py-16 sm:px-6">
                <div className="sg-card p-8 text-center sm:p-10">
                    <p className="text-6xl font-bold tracking-tight text-sg-teal">{status}</p>
                    <h1 className="mt-4 text-3xl font-bold text-sg-ink">{title}</h1>
                    <p className="mt-3 text-muted">{message}</p>
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        <Link href="/" className="sg-btn sg-btn-primary">
                            Back to APES Newsroom
                        </Link>
                        <Link href="/search" className="sg-btn sg-btn-secondary">
                            <LineIcon name="search" className="h-4 w-4" />
                            Search stories
                        </Link>
                    </div>
                </div>
            </main>
        </div>
    );
}
