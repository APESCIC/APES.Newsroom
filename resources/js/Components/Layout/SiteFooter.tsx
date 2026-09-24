import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ORG_CIC_NUMBER, ORG_LEGAL_NAME } from '../../organisationContact';
import {
    ORG_PHONE_DISPLAY,
    ORG_PHONE_TEL,
    ORG_POSTAL_ADDRESS,
    SITE_FOOTER_BRAND_BLURB,
    SITE_FOOTER_POLICIES,
    SITE_FOOTER_STAY_INFORMED,
    SITE_FOOTER_VISIT_HELP,
    type FooterNavItem,
} from '../../siteFooter';
import type { SharedPageProps } from '../../types/page';
import ApesLogo from '../Brand/ApesLogo';
import ProtectedEmail from './ProtectedEmail';

const contactLinkClassName =
    'inline-flex min-h-11 min-w-11 items-center text-on-glass-muted hover:text-on-glass';

const fineprintLinkClassName =
    'inline-flex min-h-11 items-center text-on-glass hover:underline';

function FooterPillLink({ item }: { item: FooterNavItem }) {
    const className = 'site-footer-pill-link';

    if (item.external) {
        return (
            <a href={item.href} className={className} rel="noopener noreferrer" target="_blank">
                {item.label}
            </a>
        );
    }

    return (
        <Link href={item.href} className={className}>
            {item.label}
        </Link>
    );
}

function FooterNavColumn({
    kicker,
    heading,
    items,
    labelledBy,
}: {
    kicker: string;
    heading: string;
    items: FooterNavItem[];
    labelledBy: string;
}) {
    return (
        <section className="site-footer-panel" aria-labelledby={labelledBy}>
            <p className="site-footer-kicker">{kicker}</p>
            <h3 id={labelledBy} className="site-footer-heading">
                {heading}
            </h3>
            <nav aria-label={heading}>
                <ul className="site-footer-link-list">
                    {items.map((item) => (
                        <li key={item.href}>
                            <FooterPillLink item={item} />
                        </li>
                    ))}
                </ul>
            </nav>
        </section>
    );
}

export default function SiteFooter() {
    const { currentRelease } = usePage<SharedPageProps>().props;
    const year = new Date().getFullYear();

    let versionLine: ReactNode = (
        <>
            Website version:{' '}
            <Link href="/change-log-hub" className={fineprintLinkClassName}>
                Change Log Hub
            </Link>
        </>
    );

    if (currentRelease) {
        versionLine = (
            <>
                Website version:{' '}
                <Link href={`/change-log-hub#${currentRelease.slug}`} className={fineprintLinkClassName}>
                    {currentRelease.version}
                </Link>
                {' · '}
                <Link href="/change-log-hub" className={fineprintLinkClassName}>
                    Change Log Hub
                </Link>
            </>
        );
    }

    return (
        <footer className="site-footer">
            <div className="site-footer-shell mx-auto max-w-public px-5 sm:px-6">
                <div className="site-footer-grid" aria-label="Footer navigation">
                    <section className="site-footer-panel site-footer-panel-brand" aria-labelledby="site-footer-brand">
                        <p className="site-footer-kicker">APES Newsroom</p>
                        <h2 id="site-footer-brand" className="site-footer-brand-title">
                            Stories from the APES network
                        </h2>
                        <Link href="/" aria-label="APES Newsroom home" className="mt-3 inline-flex w-fit rounded-control">
                            <ApesLogo variant="footer" alt="" className="h-16 w-16 object-contain" />
                        </Link>
                        <p className="mt-3 text-sm text-on-glass-muted">{SITE_FOOTER_BRAND_BLURB}</p>
                        <div className="mt-4 text-sm not-italic text-on-glass-muted">
                            <address className="not-italic">{ORG_POSTAL_ADDRESS}</address>
                            <p className="mt-1">
                                <a href={`tel:${ORG_PHONE_TEL}`} className={contactLinkClassName}>
                                    {ORG_PHONE_DISPLAY}
                                </a>
                            </p>
                            <p>
                                <ProtectedEmail className={contactLinkClassName} />
                            </p>
                        </div>
                    </section>

                    <FooterNavColumn
                        kicker="Services, updates and staff"
                        heading="Visit and get help"
                        labelledBy="site-footer-visit"
                        items={SITE_FOOTER_VISIT_HELP}
                    />
                    <FooterNavColumn
                        kicker="Stay connected"
                        heading="Stay informed"
                        labelledBy="site-footer-informed"
                        items={SITE_FOOTER_STAY_INFORMED}
                    />
                    <FooterNavColumn
                        kicker="Policies and legal"
                        heading="Read important information"
                        labelledBy="site-footer-policies"
                        items={SITE_FOOTER_POLICIES}
                    />
                </div>

                <div className="site-footer-fineprint">
                    <p>
                        © {year} {ORG_LEGAL_NAME}. CIC No: {ORG_CIC_NUMBER}.
                    </p>
                    <p data-nosnippet="">
                        <strong>Contact:</strong>{' '}
                        <ProtectedEmail className={fineprintLinkClassName} />
                        {' · '}
                        <a href={`tel:${ORG_PHONE_TEL}`} className={fineprintLinkClassName}>
                            {ORG_PHONE_DISPLAY}
                        </a>
                        {' · '}
                        {ORG_POSTAL_ADDRESS}
                    </p>
                    <p>{versionLine}</p>
                </div>
            </div>
        </footer>
    );
}
