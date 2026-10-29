import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

export default function Cookies() {
    return (
        <PublicLayout>
            <Head title="Cookie notice" />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <div className="form-panel prose max-w-none">
                <h1>Cookie notice</h1>
                <p>
                    We use essential cookies and similar storage required to sign you in, protect forms (CSRF), and keep
                    sessions secure. These are strictly necessary for the service to work.
                </p>
                <p>
                    We do not use third-party advertising cookies on the newsroom by default. We do not set a separate
                    analytics cookie for first-party metrics. Article and page views may be counted server-side for staff
                    metrics without storing your IP address. When you are signed in, those views can be linked to your
                    account as reading activity so staff can support members.
                </p>
                <h2>Tracking in newsletter emails</h2>
                <p>
                    Newsletter emails do not set browser cookies. They can contain two similar technologies, which you control
                    in your <Link href="/account/mailing">mailing preferences</Link> or from the preferences link in any
                    newsletter:
                </p>
                <ul>
                    <li>
                        <strong>An open-tracking pixel</strong>: a tiny image that records when the email is opened. It is only
                        included if you have turned open tracking on.
                    </li>
                    <li>
                        <strong>Click-tracking links</strong>: links that pass through a counting address (ours, or our email
                        provider Mailgun&apos;s) before reaching the story. They are used unless you turn click tracking off.
                    </li>
                </ul>
                <p>Some email apps, such as Apple Mail, load images automatically, so open counts are only an estimate.</p>
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
