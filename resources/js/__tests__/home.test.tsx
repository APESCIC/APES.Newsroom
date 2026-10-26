import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { formatStoryDate } from '../Components/Home/DeskPanel';
import Home from '../Pages/home';
import { setMockPage } from '../test/inertia';
import protectedEmailSource from '../Components/Layout/ProtectedEmail.tsx?raw';

const publicChannels = [
    { value: 'apes_cic', slug: 'apes-cic', label: 'APES CIC', description: 'Mission stories.', signup_slug: 'apes-cic' },
    {
        value: 'apes_shelter_rescue',
        slug: 'apes-shelter-rescue',
        label: 'APES Shelter & Rescue',
        description: 'Rescue news.',
        signup_slug: 'apes-shelter-rescue',
    },
    {
        value: 'apes_pet_care_clinic',
        slug: 'apes-pet-care-clinic',
        label: 'APES Pet Care Clinic',
        description: 'Clinic notes.',
        signup_slug: 'apes-pet-care-clinic',
    },
    {
        value: 'apes_times',
        slug: 'apes-times',
        label: 'APES Times',
        description: 'News and features from APES Times.',
        signup_slug: 'apes-times',
    },
    { value: 'myapes_portal', slug: 'myapes-portal', label: 'MyAPES Portal', description: null, signup_slug: 'myapes-portal' },
    { value: 'stream_4_apes', slug: 'stream-4-apes', label: 'Stream 4 APES', description: null, signup_slug: 'stream-4-apes' },
];

describe('Direction C public homepage', () => {
    let desktopMatches: boolean;
    let desktopChangeListeners: Set<(event: MediaQueryListEvent) => void>;

    const dispatchDesktopChange = (matches: boolean) => {
        desktopMatches = matches;
        const event = { matches } as MediaQueryListEvent;
        for (const listener of desktopChangeListeners) listener(event);
    };

    beforeEach(() => {
        desktopMatches = true;
        desktopChangeListeners = new Set();
        Object.defineProperty(window, 'matchMedia', {
            configurable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                get matches() {
                    return desktopMatches;
                },
                media: query,
                onchange: null,
                addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
                    desktopChangeListeners.add(listener);
                },
                removeEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
                    desktopChangeListeners.delete(listener);
                },
                addListener: vi.fn(),
                removeListener: vi.fn(),
                dispatchEvent: vi.fn(),
            })),
        });
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Editor', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
            publicChannels,
        });
    });

    it('presents the glassmorphism news hierarchy and navigation', async () => {
        const user = userEvent.setup();
        try {
            vi.stubEnv('TZ', 'America/Los_Angeles');
            expect(formatStoryDate('2026-08-07T00:30:00Z')).toBe('7 August 2026');
        } finally {
            vi.unstubAllEnvs();
        }

        render(
            <Home
                featured={{
                    title: 'Wildlife corridor project reaches a new milestone',
                    slug: 'wildlife-corridor-milestone',
                    excerpt: 'A practical update from the conservation team.',
                    channel: 'APES',
                    channel_slug: 'apes-cic',
                    author: 'Newsroom team',
                    published_at: '2026-08-05T09:00:00Z',
                }}
                recent={[
                    {
                        title: 'Shelter volunteers welcome new arrivals',
                        slug: 'shelter-new-arrivals',
                        excerpt: 'The latest from the rescue centre.',
                        channel: 'APES Shelter & Rescue',
                        channel_slug: 'apes-shelter-rescue',
                        author: 'Shelter team',
                        published_at: '2026-08-04T09:00:00Z',
                    },
                ]}
                channels={publicChannels.map((channel) => ({
                    ...channel,
                    latest:
                        channel.slug === 'apes-cic'
                            ? [{ title: 'Wildlife corridor project reaches a new milestone', slug: 'wildlife-corridor-milestone', published_at: '2026-08-05T09:00:00Z' }]
                            : [],
                }))}
            />,
        );

        expect(document.querySelector('.public-gradient-shell')).toBeInTheDocument();
        expect(document.querySelector('.glass-hero')).toBeInTheDocument();

        expect(screen.getByRole('img', { name: 'APES Newsroom' })).toHaveAttribute(
            'src',
            '/brand/apes-logo-masthead.png',
        );
        const primaryNavigation = screen.getByRole('navigation', { name: 'Primary navigation' });
        expect(within(primaryNavigation).getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');

        const channelsButton = within(primaryNavigation).getByRole('button', { name: 'Channels' });
        expect(channelsButton).toHaveAttribute('aria-expanded', 'false');
        expect(within(primaryNavigation).queryByRole('link', { name: 'APES Shelter & Rescue' })).not.toBeInTheDocument();
        await user.click(channelsButton);
        expect(channelsButton).toHaveAttribute('aria-expanded', 'true');
        const channelsPanel = document.getElementById(channelsButton.getAttribute('aria-controls') ?? '')!;
        expect(within(channelsPanel).getAllByRole('link')).toHaveLength(6);
        expect(within(channelsPanel).getByRole('link', { name: 'APES Shelter & Rescue' })).toHaveAttribute('href', '/apes-shelter-rescue');
        expect(within(channelsPanel).getByRole('link', { name: 'Stream 4 APES' })).toHaveAttribute('href', '/stream-4-apes');
        await user.keyboard('{Escape}');
        expect(channelsButton).toHaveAttribute('aria-expanded', 'false');
        expect(channelsButton).toHaveFocus();

        expect(screen.getByRole('heading', { name: 'Wildlife corridor project reaches a new milestone' })).toHaveClass('display-headline-on-glass');
        expect(document.querySelector('.editorial-rule')).not.toBeInTheDocument();
        const channelsRegion = screen.getByRole('region', { name: 'APES newsroom channels' });
        expect(within(channelsRegion).getAllByRole('article')).toHaveLength(6);
        expect(within(channelsRegion).getByRole('article', { name: 'APES CIC' })).toHaveClass('glass-channel', 'glass-channel-apes');
        expect(within(channelsRegion).getByRole('article', { name: 'APES Times' })).toHaveClass('glass-channel-times');
        expect(
            within(within(channelsRegion).getByRole('article', { name: 'APES CIC' })).getByRole('link', {
                name: 'Wildlife corridor project reaches a new milestone',
            }),
        ).toHaveAttribute('href', '/articles/wildlife-corridor-milestone');
        const timesSection = within(channelsRegion).getByRole('article', { name: 'APES Times' });
        expect(timesSection).toHaveTextContent('News and features from APES Times.');
        expect(within(timesSection).getByRole('link', { name: 'Subscribe to APES Times' })).toHaveAttribute(
            'href',
            '/newsletters/apes-times/signup',
        );
        expect(within(timesSection).queryByRole('link', { name: 'All stories from APES Times' })).not.toBeInTheDocument();
        expect(within(channelsRegion).getByRole('article', { name: 'Stream 4 APES' })).toHaveTextContent(
            'Stories from Stream 4 APES are coming soon.',
        );
        expect(
            screen.getByRole('link', {
                name: 'Read the story: Wildlife corridor project reaches a new milestone',
            }),
        ).toHaveTextContent('Read the story');
        expect(screen.getByRole('heading', { name: 'Our mission' })).toBeInTheDocument();
        expect(within(channelsRegion).getByRole('link', { name: 'APES CIC' })).toHaveAttribute('href', '/apes-cic');
        expect(within(channelsRegion).getByRole('link', { name: 'APES Pet Care Clinic' })).toHaveAttribute('href', '/apes-pet-care-clinic');
        expect(screen.getByRole('heading', { name: 'Recent stories' })).toBeInTheDocument();
        expect(screen.getByText('The latest from the rescue centre.')).toBeInTheDocument();
        expect(screen.getByText('4 August 2026', { selector: 'time' })).toHaveAttribute(
            'datetime',
            '2026-08-04T09:00:00Z',
        );
        expect(screen.queryByText('Browse archive')).not.toBeInTheDocument();

        const squareLogo = screen.getByRole('img', { name: 'Association for the Protection of Exotic Species' });
        expect(squareLogo).toHaveAttribute('src', '/brand/apes-logo-square.png');
        expect(squareLogo.parentElement?.tagName).toBe('PICTURE');
        const responsiveSource = squareLogo.parentElement?.querySelector('source[type="image/webp"]');
        expect(responsiveSource).toHaveAttribute(
            'srcset',
            '/brand/apes-logo-square-384.webp 384w, /brand/apes-logo-square-768.webp 768w',
        );
        expect(responsiveSource).toHaveAttribute('sizes', '(min-width: 768px) 384px, calc(100vw - 6.5rem)');

        const footerLogo = screen.getByRole('link', { name: 'APES Newsroom home' }).querySelector('img');
        expect(footerLogo).toHaveAttribute('src', '/brand/apes-logo-footer-64.png');

        const menuButton = screen.getByRole('button', { name: 'Open main menu' });
        const desktopHomeLink = within(primaryNavigation).getByRole('link', { name: 'Home' });
        desktopHomeLink.focus();
        act(() => dispatchDesktopChange(false));
        expect(menuButton).toHaveFocus();
        act(() => dispatchDesktopChange(true));
        expect(desktopHomeLink).toHaveFocus();

        const desktopAccountButton = within(primaryNavigation.parentElement!).getByRole('button', { name: 'Account' });
        await user.click(desktopAccountButton);
        expect(desktopAccountButton).toHaveAttribute('aria-expanded', 'true');
        act(() => dispatchDesktopChange(false));
        expect(desktopAccountButton).toHaveAttribute('aria-expanded', 'false');
        expect(menuButton).toHaveFocus();

        await user.click(menuButton);
        expect(menuButton).toHaveAttribute('aria-expanded', 'true');
        const mobileMenu = document.getElementById(menuButton.getAttribute('aria-controls') ?? '');
        expect(mobileMenu).toHaveClass('max-h-[calc(100dvh-4rem)]', 'overflow-y-auto');
        expect(screen.getAllByRole('navigation', { name: 'Primary navigation' })).toHaveLength(2);
        const mobileNavigation = screen.getAllByRole('navigation', { name: 'Primary navigation' })[1];
        const mobileChannels = within(mobileNavigation).getByRole('list', { name: 'Channels' });
        expect(within(mobileChannels).getAllByRole('link')).toHaveLength(6);
        expect(within(mobileChannels).getByRole('link', { name: 'MyAPES Portal' })).toHaveAttribute('href', '/myapes-portal');
        within(mobileNavigation).getByRole('link', { name: 'APES CIC' }).focus();
        await user.keyboard('{Escape}');
        expect(menuButton).toHaveAttribute('aria-expanded', 'false');
        expect(menuButton).toHaveFocus();

        await user.click(menuButton);
        const accountButton = within(document.getElementById(menuButton.getAttribute('aria-controls') ?? '')!).getByRole(
            'button',
            { name: 'Account' },
        );
        await user.click(accountButton);
        expect(accountButton).toHaveAttribute('aria-expanded', 'true');
        await user.keyboard('{Escape}');
        expect(accountButton).toHaveAttribute('aria-expanded', 'false');
        expect(accountButton).toHaveFocus();
        expect(menuButton).toHaveAttribute('aria-expanded', 'true');

        await user.keyboard('{Escape}');
        expect(menuButton).toHaveAttribute('aria-expanded', 'false');
        expect(menuButton).toHaveFocus();

        await user.click(menuButton);
        const reopenedMobileNavigation = screen.getAllByRole('navigation', { name: 'Primary navigation' })[1];
        within(reopenedMobileNavigation).getByRole('link', { name: 'APES CIC' }).focus();
        act(() => dispatchDesktopChange(true));
        expect(menuButton).toHaveAttribute('aria-expanded', 'false');
        expect(within(primaryNavigation).getByRole('link', { name: 'Home' })).toHaveFocus();

        act(() => dispatchDesktopChange(false));
        await user.click(menuButton);
        const mastheadHomeLink = screen.getAllByRole('link', { name: 'APES Newsroom' })[0];
        mastheadHomeLink.focus();
        act(() => dispatchDesktopChange(true));
        expect(menuButton).toHaveAttribute('aria-expanded', 'false');
        expect(mastheadHomeLink).toHaveFocus();

        const visitNav = screen.getByRole('navigation', { name: 'Visit and get help' });
        const informedNav = screen.getByRole('navigation', { name: 'Stay informed' });
        const policiesNav = screen.getByRole('navigation', { name: 'Read important information' });
        for (const nav of [visitNav, informedNav, policiesNav]) {
            for (const link of within(nav).getAllByRole('link')) {
                expect(link.className).toMatch(/site-footer-pill-link/);
            }
        }

        expect(within(visitNav).getByRole('link', { name: 'Open a ticket' })).toHaveAttribute(
            'href',
            'https://contact.apes.org.uk/',
        );
        expect(within(informedNav).getByRole('link', { name: 'Mailing lists' })).toHaveAttribute(
            'href',
            '/mailing/signup',
        );
        expect(within(policiesNav).getByRole('link', { name: 'Privacy' })).toHaveAttribute(
            'href',
            '/legal/privacy',
        );

        const footer = screen.getByRole('contentinfo');
        expect(footer).toHaveClass('site-footer');
        expect(footer).toHaveTextContent('40 Morris Street, St Helens, Merseyside, WA9 3EN');
        expect(footer).toHaveTextContent('Stories from the APES network');
        expect(footer).toHaveTextContent('Association of Protecting Exotic Species CIC');
        expect(footer).toHaveTextContent('CIC No: 16253848');

        const partners = within(footer).getByRole('region', { name: 'Partner organisations' });
        expect(within(partners).getByRole('link', { name: /British Arachnological Society/ })).toHaveAttribute(
            'href',
            'https://www.apes.org.uk/sponsors/index.html',
        );
        expect(within(partners).getByAltText('British Arachnological Society logo')).toHaveAttribute(
            'src',
            '/partners/bas.png',
        );

        const socials = within(footer).getByRole('region', { name: 'APES social media' });
        expect(within(socials).getByRole('link', { name: 'Facebook @apesorguk' })).toHaveAttribute(
            'href',
            'https://www.facebook.com/apesorguk',
        );
        expect(within(socials).getByRole('link', { name: 'Mastodon @apes' })).toHaveAttribute(
            'href',
            'https://social.apes.org.uk/@apes',
        );
        expect(within(socials).queryByRole('link', { name: /apesshelter/i })).not.toBeInTheDocument();

        const phoneLinks = within(footer).getAllByRole('link', { name: '01744 374 015' });
        expect(phoneLinks.length).toBeGreaterThanOrEqual(1);
        for (const phone of phoneLinks) {
            expect(phone).toHaveAttribute('href', 'tel:+441744374015');
        }

        const assembledEmail = ['info', '@', ['apes', 'org', 'uk'].join('.')].join('');
        const emailLinks = within(footer).getAllByRole('link', { name: assembledEmail });
        expect(emailLinks.length).toBeGreaterThanOrEqual(1);
        for (const emailLink of emailLinks) {
            expect(emailLink).toHaveAttribute('href', `mailto:${assembledEmail}`);
            expect(emailLink).toHaveAttribute('rel', 'nofollow');
        }
        expect(protectedEmailSource).not.toContain(assembledEmail);
    });
});
