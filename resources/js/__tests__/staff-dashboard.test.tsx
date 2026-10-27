import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import StaffDashboard from '../Pages/Staff/Dashboard';
import { setMockPage } from '../test/inertia';

const post = (id: number, title: string, status: string) => ({
    id,
    title,
    status,
    channel: 'apes_cic',
    author: 'Alex Editor',
    updated_at: '2026-10-20T09:00:00Z',
    scheduled_for: status === 'scheduled' ? '2026-10-22T09:00:00Z' : null,
});

const insights = {
    web: { views_7d: 12, views_30d: 40, signed_in_share_7d: 25 },
    newsletters: { sent: 10, opened: 4, clicked: 1, open_rate: 40, click_rate: 10 },
    subscriptions: null,
};

function props(overrides: Partial<Parameters<typeof StaffDashboard>[0]> = {}) {
    return {
        counts: { draft: 3, in_review: 2, scheduled: 1, published_7d: 4 },
        attention: { review: null, drafts: [post(1, 'Draft about otters', 'draft')], scheduled: [post(2, 'Weekend rescue', 'scheduled')] },
        recent: [post(1, 'Draft about otters', 'draft')],
        insights,
        canReview: false,
        ...overrides,
    };
}

describe('Staff dashboard', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 2, name: 'Sam Staff', email: 'sam@example.test', role: 'staff' },
                can: { accessStaff: true, accessAdmin: false, reviewPosts: false },
            },
        });
    });

    it('renders in the staff panel with Dashboard current and stat cards linking to filtered lists', () => {
        render(<StaffDashboard {...props()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeInTheDocument();
        expect(document.querySelector('title')).toHaveTextContent('Dashboard - Staff');
        const nav = screen.getByRole('navigation', { name: 'Staff workspace' });
        expect(within(nav).getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');

        const stats = screen.getByRole('group', { name: 'Post summary' });
        expect(within(stats).getByRole('link', { name: /Drafts\s*3/ })).toHaveAttribute('href', '/staff/posts?status=draft');
        expect(within(stats).getByRole('link', { name: /In review\s*2/ })).toHaveAttribute('href', '/staff/posts?status=in_review');
        expect(within(stats).getByRole('link', { name: /Scheduled\s*1/ })).toHaveAttribute('href', '/staff/posts?status=scheduled');
    });

    it('offers quick actions and lists items needing attention', () => {
        render(<StaffDashboard {...props()} />);

        expect(screen.getByRole('link', { name: 'New post' })).toHaveAttribute('href', '/staff/posts/new');
        expect(screen.getByRole('link', { name: 'New page' })).toHaveAttribute('href', '/staff/pages/new');
        expect(screen.getByRole('link', { name: 'New newsletter' })).toHaveAttribute('href', '/staff/newsletters/new');

        const drafts = screen.getByRole('region', { name: 'Your drafts' });
        expect(within(drafts).getByRole('link', { name: 'Draft about otters' })).toHaveAttribute('href', '/staff/posts/1/edit');
        expect(within(screen.getByRole('region', { name: 'Scheduled' })).getByText(/Goes live/)).toBeInTheDocument();
    });

    it('hides the review queue and revenue from staff', () => {
        render(<StaffDashboard {...props()} />);

        expect(screen.queryByRole('region', { name: 'Review queue' })).not.toBeInTheDocument();
        expect(screen.queryByText(/MRR/)).not.toBeInTheDocument();
        expect(screen.queryByTestId('advanced-details')).not.toBeInTheDocument();
    });

    it('shows the review queue and revenue behind an Advanced disclosure for admins', () => {
        render(
            <StaffDashboard
                {...props({
                    canReview: true,
                    attention: { review: [post(5, 'Needs a second look', 'in_review')], drafts: [], scheduled: [] },
                    insights: { ...insights, subscriptions: { free: 1, active: 2, past_due: 0, canceled: 0, mrr_pence: 1200 } },
                })}
            />,
        );

        expect(within(screen.getByRole('region', { name: 'Review queue' })).getByRole('link', { name: 'Needs a second look' })).toBeInTheDocument();
        expect(screen.getByText('You have no drafts in progress.')).toBeInTheDocument();

        const advanced = screen.getByTestId('advanced-details');
        expect(advanced.tagName).toBe('DETAILS');
        expect(advanced).not.toHaveAttribute('open');
        expect(within(advanced).getByText('MRR (test mode)')).toBeInTheDocument();
        expect(within(advanced).getByText('£12.00')).toBeInTheDocument();
    });
});
