import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ORG_CIC_NUMBER, ORG_LEGAL_NAME } from '../../organisationContact';
import {
    ORG_PHONE_DISPLAY,
    ORG_PHONE_TEL,
    ORG_POSTAL_ADDRESS,
    SITE_FOOTER_BRAND_BLURB,
    SITE_FOOTER_PARTNERS,
    SITE_FOOTER_PARTNERS_HREF,
    SITE_FOOTER_POLICIES,
    SITE_FOOTER_SOCIALS,
    SITE_FOOTER_STAY_INFORMED,
    SITE_FOOTER_VISIT_HELP,
    type FooterNavItem,
    type FooterSocial,
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

function SocialIcon({ label }: { label: FooterSocial['label'] }) {
    const icons: Record<FooterSocial['label'], ReactNode> = {
        Facebook: (
            <svg viewBox="0 0 16 16" aria-hidden="true" className="site-footer-social-icon">
                <path
                    fill="currentColor"
                    d="M9.7 3H12v2.8H9.9c-.8 0-1.2.4-1.2 1.1V8H12l-.4 2.8H8.7V15H5.9v-4.2H4V8h1.9V6.6C5.9 4.4 7.4 3 9.7 3z"
                />
            </svg>
        ),
        Instagram: (
            <svg viewBox="0 0 16 16" aria-hidden="true" className="site-footer-social-icon">
                <rect
                    x="2.2"
                    y="2.2"
                    width="11.6"
                    height="11.6"
                    rx="3"
                    ry="3"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.5"
                />
                <circle cx="8" cy="8" r="2.7" fill="none" stroke="currentColor" strokeWidth="1.5" />
                <circle cx="11.7" cy="4.4" r="0.9" fill="currentColor" />
            </svg>
        ),
        X: (
            <svg viewBox="0 0 16 16" aria-hidden="true" className="site-footer-social-icon">
                <path d="M3 3l10 10M13 3L3 13" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            </svg>
        ),
        YouTube: (
            <svg viewBox="0 0 16 16" aria-hidden="true" className="site-footer-social-icon">
                <rect x="1.5" y="3.5" width="13" height="9" rx="2" fill="none" stroke="currentColor" strokeWidth="1.4" />
                <path fill="currentColor" d="M7 6.2v3.6l3.2-1.8z" />
            </svg>
        ),
        Threads: (
            <svg viewBox="0 0 16 16" aria-hidden="true" className="site-footer-social-icon">
                <text
                    x="8"
                    y="11.2"
                    textAnchor="middle"
                    fontSize="10"
                    fontFamily="Arial, Helvetica, sans-serif"
                    fill="currentColor"
                >
                    @
                </text>
            </svg>
        ),
        Bluesky: (
            <svg viewBox="0 0 16 16" aria-hidden="true" className="site-footer-social-icon">
                <path
                    fill="currentColor"
                    d="M4 4.1c1.3.8 2.3 1.9 4 4 1.7-2.1 2.7-3.2 4-4 .8-.5 1.6 0 1.4 1-.3 1.6-1.3 2.8-2.5 3.9.8.2 1.6.7 2.1 1.4.7.9.3 2.3-1.2 2.3-1.2 0-2.2-.8-2.8-1.7-.3-.4-.6-.9-1-1.5-.4.6-.7 1.1-1 1.5-.6.9-1.6 1.7-2.8 1.7-1.5 0-1.9-1.4-1.2-2.3.5-.7 1.3-1.2 2.1-1.4-1.2-1.1-2.2-2.3-2.5-3.9-.2-1 .6-1.5 1.4-1z"
                />
            </svg>
        ),
        Mastodon: (
            <svg viewBox="0 0 16 16" aria-hidden="true" className="site-footer-social-icon">
                <path
                    fill="currentColor"
                    d="M3 4.4C3 2.9 4.2 2 5.8 2h4.4C11.8 2 13 2.9 13 4.4v4.1c0 2.4-1.4 3.6-3.7 3.8l-1.3 1.7h-1l.5-1.7H5.8C4.2 12.1 3 11.1 3 9.4V4.4zm2 .7v4.1h1.3V5.8l1.7 2.6h.3L10 5.8v3.4h1.3V5.1h-1.5l-1.6 2.4L6.5 5.1H5z"
                />
            </svg>
        ),
    };

    return icons[label];
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

                <div className="site-footer-lower">
                    <section className="site-footer-partners" aria-label="Partner organisations">
                        <strong>In partnership with</strong>
                        {SITE_FOOTER_PARTNERS.map((partner) => (
                                <a
                                    key={partner.name}
                                    className="site-footer-partner-pill min-h-11"
                                    href={SITE_FOOTER_PARTNERS_HREF}
                                    rel="noopener noreferrer"
                                    target="_blank"
                                >
                                <img src={partner.logoSrc} alt={partner.logoAlt} width={34} height={34} />
                                <span>{partner.name}</span>
                            </a>
                        ))}
                    </section>

                    <section className="site-footer-social-block" aria-label="APES social media">
                        <strong>Stay connected</strong>
                        <div className="site-footer-social-row">
                            {SITE_FOOTER_SOCIALS.map((social) => (
                                <a
                                    key={social.href}
                                    className="site-footer-social-link min-h-11 min-w-11"
                                    href={social.href}
                                    rel="noopener noreferrer"
                                    target="_blank"
                                    aria-label={social.ariaLabel}
                                >
                                    <SocialIcon label={social.label} />
                                    <span className="site-footer-social-label">{social.label}</span>
                                </a>
                            ))}
                        </div>
                    </section>
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
