import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

export default function Rights() {
    return (
        <PublicLayout>
            <Head title="Your data rights" />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <div className="glass-form-panel prose max-w-none">
                <h1>Your data rights</h1>
                <ul>
                    <li>
                        <strong>Access / portability:</strong> signed-in users can download their data from{' '}
                        <Link href="/account/export">Account → Export</Link>.
                    </li>
                    <li>
                        <strong>Rectification:</strong> update your name and related details under{' '}
                        <Link href="/account">Account</Link>.
                    </li>
                    <li>
                        <strong>Erasure:</strong> delete your account from the account settings page. By default we keep your
                        newsletter subscriptions as a mailing-list-only contact (no account). Uncheck{' '}
                        <em>Keep my newsletter subscriptions</em> on the account page if you want those removed too.
                    </li>
                    <li>
                        <strong>Mailing consent:</strong> manage lists at <Link href="/account/mailing">Account → Mailing</Link>,
                        use signed preference links in emails, or <Link href="/mailing/unsubscribe">unsubscribe</Link>.
                    </li>
                </ul>
                </div>
            </main>
        </PublicLayout>
    );
}
