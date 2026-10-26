import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import ChannelShow from '../Pages/Channels/Show';
import { getInertiaMock, setMockPage } from '../test/inertia';

const publicChannels = [
    { value: 'apes_cic', slug: 'apes-cic', label: 'APES CIC', description: 'Mission stories.', signup_slug: 'apes-cic' },
    {
        value: 'apes_times',
        slug: 'apes-times',
        label: 'APES Times',
        description: 'News and features from APES Times.',
        signup_slug: 'apes-times',
    },
];

const seo = {
    title: 'APES Times - APES Newsroom',
    description: 'News and features from APES Times.',
    url: 'https://www.example.test/apes-times',
    image: 'https://www.example.test/brand/apes-logo-compact.png',
    rss: 'https://www.example.test/apes-times/rss.xml',
};

describe('Channel page', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: null, can: { accessStaff: false, accessAdmin: false } },
            publicChannels,
        });
        (getInertiaMock().page as { url?: string }).url = '/apes-times?page=2';
    });

    it('shows the channel, a subscribe button, SEO tags and marks the menu entry as current', async () => {
        const user = userEvent.setup();
        render(
            <ChannelShow
                channel={publicChannels[1]}
                posts={{
                    data: [
                        {
                            title: 'Our autumn issue',
                            slug: 'our-autumn-issue',
                            excerpt: 'Highlights from the season.',
                            author: 'Times desk',
                            published_at: '2026-10-01T09:00:00Z',
                        },
                    ],
                }}
                seo={seo}
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'APES Times' })).toBeInTheDocument();
        expect(screen.getByText('News and features from APES Times.', { selector: 'p' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Subscribe to APES Times' })).toHaveAttribute('href', '/newsletters/apes-times/signup');
        expect(screen.getByRole('link', { name: 'Our autumn issue' })).toHaveAttribute('href', '/articles/our-autumn-issue');

        expect(document.querySelector('meta[property="og:title"]')).toHaveAttribute('content', seo.title);
        expect(document.querySelector('meta[property="og:image"]')).toHaveAttribute('content', seo.image);
        expect(document.querySelector('meta[name="description"]')).toHaveAttribute('content', seo.description);
        expect(document.querySelector('link[rel="canonical"]')).toHaveAttribute('href', seo.url);
        expect(document.querySelector('link[rel="alternate"]')).toHaveAttribute('href', seo.rss);

        const desktopNavigation = screen.getAllByRole('navigation', { name: 'Primary navigation' })[0];
        expect(within(desktopNavigation).getByRole('link', { name: 'Home' })).not.toHaveAttribute('aria-current');
        await user.click(within(desktopNavigation).getByRole('button', { name: 'Channels' }));
        expect(within(desktopNavigation).getByRole('link', { name: 'APES Times' })).toHaveAttribute('aria-current', 'page');
        expect(within(desktopNavigation).getByRole('link', { name: 'APES CIC' })).not.toHaveAttribute('aria-current');

        await user.click(document.body);
        expect(within(desktopNavigation).getByRole('button', { name: 'Channels' })).toHaveAttribute('aria-expanded', 'false');
    });

    it('shows an empty state with a subscribe prompt when there are no posts', () => {
        render(<ChannelShow channel={publicChannels[1]} posts={{ data: [] }} seo={seo} />);

        expect(screen.getByText('No published stories in APES Times yet. Subscribe to hear when the first one is out.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Subscribe to APES Times' })).toBeInTheDocument();
    });
});
