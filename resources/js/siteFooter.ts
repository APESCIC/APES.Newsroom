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

export const SITE_FOOTER_PARTNERS_HREF = 'https://www.apes.org.uk/sponsors/index.html';

export type FooterPartner = {
    name: string;
    logoSrc: string;
    logoAlt: string;
};

/** Organisation-wide partner marks (vendored under public/partners/). */
export const SITE_FOOTER_PARTNERS: FooterPartner[] = [
    {
        name: 'British Arachnological Society',
        logoSrc: '/partners/bas.png',
        logoAlt: 'British Arachnological Society logo',
    },
    {
        name: 'FBH',
        logoSrc: '/partners/fbh.webp',
        logoAlt: 'Federation of British Herpetologists logo',
    },
    {
        name: 'Merseyside Police',
        logoSrc: '/partners/merseyside-police.png',
        logoAlt: 'Merseyside Police logo',
    },
];

export type FooterSocial = {
    label: string;
    href: string;
    ariaLabel: string;
};

/** Organisation-wide socials from www.apes.org.uk (not @apesshelter). */
export const SITE_FOOTER_SOCIALS: FooterSocial[] = [
    {
        label: 'Facebook',
        href: 'https://www.facebook.com/apesorguk',
        ariaLabel: 'Facebook @apesorguk',
    },
    {
        label: 'Instagram',
        href: 'https://www.instagram.com/apesorguk',
        ariaLabel: 'Instagram @apesorguk',
    },
    {
        label: 'X',
        href: 'https://www.x.com/apesorguk',
        ariaLabel: 'X @apesorguk',
    },
    {
        label: 'YouTube',
        href: 'https://www.youtube.com/@apesorguk',
        ariaLabel: 'YouTube @apesorguk',
    },
    {
        label: 'Threads',
        href: 'https://www.threads.net/@apesorguk',
        ariaLabel: 'Threads @apesorguk',
    },
    {
        label: 'Bluesky',
        href: 'https://bsky.app/profile/apesorguk.bsky.social',
        ariaLabel: 'Bluesky @apesorguk',
    },
    {
        label: 'Mastodon',
        href: 'https://social.apes.org.uk/@apes',
        ariaLabel: 'Mastodon @apes',
    },
];

export { ORG_PHONE_DISPLAY, ORG_PHONE_TEL, ORG_POSTAL_ADDRESS };
