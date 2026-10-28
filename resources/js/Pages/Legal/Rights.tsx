import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

export default function Rights() {
    return (
        <PublicLayout>
            <Head title="Your data rights" />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <div className="form-panel prose max-w-none">
                <h1>Your data rights</h1>
                <ul>
                    <li>
                        <strong>Access / portability:</strong> signed-in users can download their data, including any contact
                        details they have added, from <Link href="/account/export">Account → Export</Link>.
                    </li>
                    <li>
                        <strong>Rectification:</strong> update your name, postal address, mobile number and WhatsApp contact
                        under <Link href="/account">Account</Link>. Clear a contact field to remove it.
                    </li>
                    <li>
                        <strong>Erasure:</strong> delete your account from the account settings page. By default we keep your
                        newsletter subscriptions as a mailing-list-only contact (no account). Uncheck{' '}
                        <em>Keep my newsletter subscriptions</em> on the account page if you want those removed too. If your
                        account cannot be deleted from the account page (for example because you have written articles), ask
                        us: an administrator will delete it or, where articles must stay published, remove your name, email,
                        contact details and sign-in methods so the account can no longer identify you.
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
