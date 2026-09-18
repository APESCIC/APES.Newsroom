import { Link, usePage } from '@inertiajs/react';
import { ORG_PHONE_DISPLAY, ORG_PHONE_TEL, ORG_POSTAL_ADDRESS } from '../../organisationContact';
import type { SharedPageProps } from '../../types/page';
import ApesLogo from '../Brand/ApesLogo';
import ProtectedEmail from './ProtectedEmail';

const contactLinkClassName =
    'inline-flex min-h-11 min-w-11 items-center hover:text-on-glass';

export default function SiteFooter() {
    const { currentRelease } = usePage<SharedPageProps>().props;

    return (
        <footer className="glass-panel border-t border-white/10 py-10">
            <div className="mx-auto flex max-w-public flex-col gap-6 px-5 sm:px-6 md:flex-row md:items-start md:justify-between">
                <Link href="/" aria-label="APES Newsroom home" className="inline-flex w-fit rounded-control">
                    <ApesLogo variant="footer" alt="" className="h-16 w-16 object-contain" />
                </Link>
                <div className="text-sm not-italic text-on-glass-muted">
                    <address className="not-italic">{ORG_POSTAL_ADDRESS}</address>
                    <p className="mt-1">
                        <a href={`tel:${ORG_PHONE_TEL}`} className={contactLinkClassName}>
                            {ORG_PHONE_DISPLAY}
                        </a>
                    </p>
                    <p>
                        <ProtectedEmail className={contactLinkClassName} />
                    </p>
                    <p className="mt-3">
                        {currentRelease ? (
                            <>
                                Website version:{' '}
                                <Link
                                    href={`/change-log-hub#${currentRelease.slug}`}
                                    className="inline-flex min-h-11 items-center hover:text-on-glass"
                                >
                                    {currentRelease.version}
                                </Link>
                                {' · '}
                                <Link href="/change-log-hub" className="inline-flex min-h-11 items-center hover:text-on-glass">
                                    Change Log Hub
                                </Link>
                            </>
                        ) : (
                            <Link href="/change-log-hub" className="inline-flex min-h-11 items-center hover:text-on-glass">
                                Change Log Hub
                            </Link>
                        )}
                    </p>
                </div>
                <nav aria-label="Legal and subscriptions">
                    <ul className="flex flex-wrap gap-x-6 gap-y-3 text-sm text-on-glass-muted">
                        <li><Link href="/legal/privacy" className="inline-flex min-h-11 min-w-11 items-center justify-center hover:text-on-glass">Privacy</Link></li>
                        <li><Link href="/legal/cookies" className="inline-flex min-h-11 min-w-11 items-center justify-center hover:text-on-glass">Cookies</Link></li>
                        <li><Link href="/legal/rights" className="inline-flex min-h-11 min-w-11 items-center justify-center hover:text-on-glass">Your rights</Link></li>
                        <li><Link href="/mailing/signup" className="inline-flex min-h-11 min-w-11 items-center justify-center hover:text-on-glass">Mailing lists</Link></li>
                        <li><Link href="/change-log-hub" className="inline-flex min-h-11 min-w-11 items-center justify-center hover:text-on-glass">Change Log Hub</Link></li>
                    </ul>
                </nav>
            </div>
        </footer>
    );
}
