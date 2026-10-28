import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PanelError from '../Pages/Errors/Panel';
import { setMockPage } from '../test/inertia';

describe('In-panel error pages', () => {
    it('keeps the panel navigation on a 404 and links back to the dashboard', () => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true, reviewPosts: true },
            },
        });

        render(
            <PanelError
                status={404}
                title="Page not found"
                message="We could not find that page in the Admin panel."
                area="Admin"
                home="/admin"
                requiredRole={null}
            />,
        );

        expect(screen.getByRole('navigation', { name: 'Admin workspace' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1, name: 'Page not found' })).toBeInTheDocument();
        expect(document.querySelector('title')).toHaveTextContent('404 Page not found - Admin');
        expect(screen.getByRole('link', { name: 'Go to the Admin dashboard' })).toHaveAttribute('href', '/admin');
        expect(within(screen.getByRole('navigation', { name: 'Admin workspace' })).queryByRole('link', { current: 'page' })).not.toBeInTheDocument();
    });

    it('explains the role a 403 needs', () => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 2, name: 'Sam Staff', email: 'sam@example.test', role: 'staff' },
                can: { accessStaff: true, accessAdmin: false, reviewPosts: false },
            },
        });

        render(
            <PanelError
                status={403}
                title="You do not have access"
                message="This page needs the Admin role. You are signed in as Staff."
                area="Staff"
                home="/staff"
                requiredRole="Admin"
            />,
        );

        expect(screen.getByRole('navigation', { name: 'Staff workspace' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'Admin role required' })).toBeInTheDocument();
        expect(screen.getByText('This page needs the Admin role. You are signed in as Staff.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Go to the Staff dashboard' })).toHaveAttribute('href', '/staff');
    });
});
