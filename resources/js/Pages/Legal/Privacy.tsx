import { Head, Link } from '@inertiajs/react';
import ProtectedEmail from '../../Components/Layout/ProtectedEmail';
import PublicLayout from '../../Components/Layout/PublicLayout';
import { ORG_PHONE_DISPLAY, ORG_PHONE_TEL, ORG_POSTAL_ADDRESS } from '../../organisationContact';

export default function Privacy() {
    return (
        <PublicLayout>
            <Head title="Privacy notice" />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <div className="glass-form-panel prose max-w-none">
                <h1>Privacy notice</h1>
                <p>
                    APES Newsroom is operated by APES CIC. This draft notice explains how we process personal data for
                    public accounts, mailing lists, and community features. It awaits formal legal approval before
                    production cutover.
                </p>
                <h2>What we collect</h2>
                <ul>
                    <li>Account details you provide (name, email, password or magic-link authentication).</li>
                    <li>Mailing list preferences and consent records when you subscribe.</li>
                    <li>Optional public profile, comments, and reactions if you use those features.</li>
                    <li>Technical logs needed to keep the service secure.</li>
                </ul>
                <h2>Your rights</h2>
                <p>
                    See the <Link href="/legal/rights">rights page</Link> for access, export, correction, erasure, and
                    mailing preference controls. If you delete your account, newsletter subscriptions are kept as a
                    mailing-list-only contact unless you choose to remove them on the account page.
                </p>
                <h2>Contact</h2>
                <p>Privacy questions: write to APES CIC at the address below, call, or email.</p>
                <address className="not-italic">
                    {ORG_POSTAL_ADDRESS}
                    <br />
                    <a href={`tel:${ORG_PHONE_TEL}`}>{ORG_PHONE_DISPLAY}</a>
                    <br />
                    <ProtectedEmail />
                </address>
            </div>
            </main>
        </PublicLayout>
    );
}
