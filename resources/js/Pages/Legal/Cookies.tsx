import { Head } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

export default function Cookies() {
    return (
        <PublicLayout>
            <Head title="Cookie notice" />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <div className="glass-form-panel prose max-w-none">
                <h1>Cookie notice</h1>
                <p>
                    We use essential cookies and similar storage required to sign you in, protect forms (CSRF), and keep
                    sessions secure. These are strictly necessary for the service to work.
                </p>
                <p>
                    We do not use third-party advertising cookies on the newsroom. We do not set a separate analytics
                    cookie. Article and page views may be counted server-side for staff metrics without storing your IP
                    address. When you are signed in, those views can be linked to your account as reading activity so
                    staff can support members. Newsletter open and click tracking uses signed links in email, not a
                    browser cookie.
                </p>
                </div>
            </main>
        </PublicLayout>
    );
}
