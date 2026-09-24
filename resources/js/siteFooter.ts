import { ORG_PHONE_DISPLAY, ORG_PHONE_TEL, ORG_POSTAL_ADDRESS } from './organisationContact';

export const SITE_FOOTER_BRAND_BLURB =
    'News and updates from Association of Protecting Exotic Species CIC — covering APES, Shelter & Rescue, and Pet Care Clinic.';

export type FooterNavItem = {
    label: string;
    href: string;
    external?: boolean;
};

export const SITE_FOOTER_VISIT_HELP: FooterNavItem[] = [
    { label: 'Open a ticket', href: 'https://contact.apes.org.uk/', external: true },
    { label: 'APES CIC', href: 'https://www.apes.org.uk/', external: true },
    { label: 'Shelter & Rescue', href: 'https://www.apesshelter.org.uk/', external: true },
];

export const SITE_FOOTER_STAY_INFORMED: FooterNavItem[] = [
    { label: 'Mailing lists', href: '/mailing/signup' },
    { label: 'Change Log Hub', href: '/change-log-hub' },
    { label: 'MyAPES Account', href: 'https://myapes.me.uk', external: true },
];

export const SITE_FOOTER_POLICIES: FooterNavItem[] = [
    { label: 'Privacy', href: '/legal/privacy' },
    { label: 'Cookies', href: '/legal/cookies' },
    { label: 'Your rights', href: '/legal/rights' },
];

export { ORG_PHONE_DISPLAY, ORG_PHONE_TEL, ORG_POSTAL_ADDRESS };
