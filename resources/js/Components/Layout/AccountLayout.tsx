import { type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import PublicLayout from './PublicLayout';

type AccountLayoutProps = {
    title: string;
    description?: string;
    backHref?: string;
    backLabel?: string;
    children: ReactNode;
};

export default function AccountLayout({
    title,
    description,
    backHref,
    backLabel = '← Back',
    children,
}: AccountLayoutProps) {
    return (
        <PublicLayout>
            <main id="main-content" className="mx-auto max-w-lg px-5 py-12 sm:px-6">
                {backHref && (
                    <Link href={backHref} className="text-link inline-flex min-h-11 items-center text-sm">
                        {backLabel}
                    </Link>
                )}
                <div className={`form-panel sm:p-8 ${backHref ? 'mt-4' : ''}`}>
                    <h1 className="text-2xl font-bold text-body">{title}</h1>
                    {description && <p className="mt-2 text-sm text-muted">{description}</p>}
                    {children}
                </div>
            </main>
        </PublicLayout>
    );
}
