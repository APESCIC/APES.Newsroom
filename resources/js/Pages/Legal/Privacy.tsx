import { Head, Link } from '@inertiajs/react';
import ProtectedEmail from '../../Components/Layout/ProtectedEmail';
import PublicLayout from '../../Components/Layout/PublicLayout';
import { ORG_PHONE_DISPLAY, ORG_PHONE_TEL, ORG_POSTAL_ADDRESS } from '../../organisationContact';

export default function Privacy() {
    return (
        <PublicLayout>
            <Head title="Privacy notice" />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <div className="form-panel prose max-w-none">
                <h1>Privacy notice</h1>
                <p>
                    APES Newsroom is operated by APES CIC. This draft notice explains how we process personal data for
                    public accounts, mailing lists, and community features. It awaits formal legal approval before
                    production cutover.
                </p>
                <h2>What we collect</h2>
                <ul>
                    <li>Account details you provide (name, email, password or magic-link authentication).</li>
                    <li>Optional contact details: postal address, mobile number and WhatsApp number or username.</li>
                    <li>Mailing list preferences and consent records when you subscribe.</li>
                    <li>Optional public profile, comments, and reactions if you use those features.</li>
                    <li>Technical logs needed to keep the service secure.</li>
                </ul>
                <h2>Optional contact details</h2>
                <p>
                    You can add a postal address, mobile number and WhatsApp contact on your account page. They are optional,
                    and we use them only to contact you about your account or membership when email is not enough.
                </p>
                <ul>
                    <li>
                        <strong>Who can see them:</strong> you and APES administrators. They are never shown on your public
                        profile, in comments, in newsletters or through our APIs.
                    </li>
                    <li>
                        <strong>Lawful basis:</strong> your consent. You can change or remove them at any time on the account
                        page.
                    </li>
                    <li>
                        <strong>Storage and retention:</strong> they are stored encrypted and kept while your account exists.
                        They are included in your data download and erased when you clear them or your account is deleted.
                    </li>
                    <li>
                        <strong>Changes by administrators:</strong> if an administrator corrects these details, the change and
                        its reason are recorded in our audit log without the values themselves.
                    </li>
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
