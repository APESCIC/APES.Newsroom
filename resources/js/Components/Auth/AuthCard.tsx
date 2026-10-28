import { type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import ApesLogo from '../Brand/ApesLogo';

type AuthCardProps = {
    title: string;
    description?: string;
    children: ReactNode;
};

export default function AuthCard({ title, description, children }: AuthCardProps) {
    return (
        <div className="public-shell flex flex-col">
            <header className="public-header px-5 py-3 sm:px-6">
                <Link href="/" className="inline-flex rounded-control">
                    <ApesLogo variant="compact" className="h-10 w-auto object-contain" alt="APES Newsroom home" />
                </Link>
            </header>
            <main id="main-content" className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-5 py-12 sm:px-6">
                <div className="form-panel sm:p-8">
                    <h1 className="text-2xl font-bold text-body">{title}</h1>
                    {description && <p className="mt-2 text-sm text-muted">{description}</p>}
                    <div className="mt-6">{children}</div>
                </div>
            </main>
        </div>
    );
}
