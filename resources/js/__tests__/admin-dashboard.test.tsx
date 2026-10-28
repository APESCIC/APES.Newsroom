import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import AdminDashboard from '../Pages/Admin/Dashboard';
import { setMockPage } from '../test/inertia';

describe('Admin dashboard', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true, reviewPosts: true },
            },
        });
    });

    it('shows moderation counts, the latest release, shortcuts and audit events', () => {
        render(
            <AdminDashboard
                moderation={{ profiles: 2, comments: 5, reports: 1, suspended: 0 }}
                release={{ version: 'v1.21.0', summary: 'Panel dashboards.', released_at: '2026-10-27' }}
                audit={[{ id: 9, action: 'settings.updated', actor: 'Alex Admin', subject_type: 'User', created_at: '2026-10-27T10:00:00Z' }]}
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeInTheDocument();
        expect(document.querySelector('title')).toHaveTextContent('Dashboard - Admin');
        expect(within(screen.getByRole('navigation', { name: 'Admin workspace' })).getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
            'aria-current',
            'page',
        );

        const moderation = screen.getByRole('group', { name: 'Moderation summary' });
        expect(within(moderation).getByRole('link', { name: /Comments\s*5/ })).toHaveAttribute('href', '/admin/moderation');

        const release = screen.getByRole('region', { name: 'Latest release' });
        expect(within(release).getByText('v1.21.0')).toBeInTheDocument();
        expect(within(release).getByRole('link', { name: 'Change Log Hub' })).toHaveAttribute('href', '/change-log-hub');

        const shortcuts = screen.getByRole('region', { name: 'Settings shortcuts' });
        expect(within(shortcuts).getByRole('link', { name: /Sending domains/ })).toHaveAttribute('href', '/admin/settings/email-domains');

        const audit = screen.getByRole('region', { name: 'Recent audit events' });
        expect(within(audit).getByText('settings.updated')).toBeInTheDocument();
        expect(within(audit).getByRole('link', { name: 'Full audit log' })).toHaveAttribute('href', '/admin/audit-log');
    });

    it('copes with no release and hides audit events when not provided', () => {
        render(<AdminDashboard moderation={{ profiles: 0, comments: 0, reports: 0, suspended: 0 }} release={null} audit={null} />);

        expect(screen.getByText('No release has been published yet.')).toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Recent audit events' })).not.toBeInTheDocument();
    });
});
