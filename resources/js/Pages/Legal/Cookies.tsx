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
                    We do not use third-party advertising cookies on the newsroom by default. We do not set a separate
                    analytics cookie for first-party metrics. Article and page views may be counted server-side for staff
                    metrics without storing your IP address. When you are signed in, those views can be linked to your
                    account as reading activity so staff can support members. Newsletter open and click tracking uses
                    signed links in email, not a browser cookie.
                </p>
                <p>
                    If the site operator enables an optional third-party analytics provider (for example Plausible or a
                    Google Tag Manager stub), that provider&apos;s scripts load only after you accept analytics cookies
                    via the on-site consent prompt. You can decline; native staff metrics continue without third-party
                    scripts. The consent choice is stored in a first-party cookie named{' '}
                    <code>newsroom_analytics_consent</code>.
                </p>
                </div>
            </main>
        </PublicLayout>
    );
}
