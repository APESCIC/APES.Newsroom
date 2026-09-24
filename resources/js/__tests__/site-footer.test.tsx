import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import SiteFooter from '../Components/Layout/SiteFooter';
import {
    SITE_FOOTER_PARTNERS,
    SITE_FOOTER_POLICIES,
    SITE_FOOTER_SOCIALS,
    SITE_FOOTER_STAY_INFORMED,
    SITE_FOOTER_VISIT_HELP,
} from '../siteFooter';
import { setMockPage } from '../test/inertia';

describe('SiteFooter', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: null,
                can: { accessStaff: false, accessAdmin: false },
            },
            currentRelease: {
                version: 'v1.6.4',
                slug: 'release-v164',
            },
        });
    });

    it('exposes a footer landmark with brand, columns, partners, socials, and fineprint', () => {
        render(<SiteFooter />);

        const footer = screen.getByRole('contentinfo');
        expect(footer).toHaveClass('site-footer');
        expect(within(footer).getByRole('heading', { name: 'Stories from the APES network' })).toBeInTheDocument();
        expect(within(footer).getByRole('link', { name: 'APES Newsroom home' })).toHaveAttribute('href', '/');

        const visitNav = within(footer).getByRole('navigation', { name: 'Visit and get help' });
        const informedNav = within(footer).getByRole('navigation', { name: 'Stay informed' });
        const policiesNav = within(footer).getByRole('navigation', { name: 'Read important information' });

        for (const item of SITE_FOOTER_VISIT_HELP) {
            const link = within(visitNav).getByRole('link', { name: item.label });
            expect(link).toHaveAttribute('href', item.href);
            expect(link.className).toMatch(/site-footer-pill-link/);
        }
        for (const item of SITE_FOOTER_STAY_INFORMED) {
            expect(within(informedNav).getByRole('link', { name: item.label })).toHaveAttribute('href', item.href);
        }
        for (const item of SITE_FOOTER_POLICIES) {
            expect(within(policiesNav).getByRole('link', { name: item.label })).toHaveAttribute('href', item.href);
        }

        expect(footer).toHaveTextContent('Association of Protecting Exotic Species CIC');
        expect(footer).toHaveTextContent('CIC No: 16253848');
        expect(within(footer).getByRole('link', { name: 'v1.6.4' })).toHaveAttribute(
            'href',
            '/change-log-hub#release-v164',
        );

        const partners = within(footer).getByRole('region', { name: 'Partner organisations' });
        expect(within(partners).getAllByRole('link')).toHaveLength(SITE_FOOTER_PARTNERS.length);
        for (const partner of SITE_FOOTER_PARTNERS) {
            expect(within(partners).getByAltText(partner.logoAlt)).toHaveAttribute('src', partner.logoSrc);
        }

        const socials = within(footer).getByRole('region', { name: 'APES social media' });
        for (const social of SITE_FOOTER_SOCIALS) {
            const link = within(socials).getByRole('link', { name: social.ariaLabel });
            expect(link).toHaveAttribute('href', social.href);
            expect(link.className).toMatch(/site-footer-social-link/);
            expect(link).toHaveClass('min-h-11', 'min-w-11');
        }
        expect(within(socials).queryByRole('link', { name: /apesshelter/i })).not.toBeInTheDocument();
    });

    it('uses noopener noreferrer on external footer destinations', () => {
        render(<SiteFooter />);
        const footer = screen.getByRole('contentinfo');

        for (const item of [...SITE_FOOTER_VISIT_HELP, ...SITE_FOOTER_STAY_INFORMED].filter((entry) => entry.external)) {
            expect(within(footer).getByRole('link', { name: item.label })).toHaveAttribute(
                'rel',
                'noopener noreferrer',
            );
        }

        for (const social of SITE_FOOTER_SOCIALS) {
            expect(within(footer).getByRole('link', { name: social.ariaLabel })).toHaveAttribute(
                'rel',
                'noopener noreferrer',
            );
        }
    });
});
