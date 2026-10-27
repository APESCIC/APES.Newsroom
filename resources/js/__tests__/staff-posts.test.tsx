import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PostsIndex from '../Pages/Staff/Posts/Index';
import { setMockPage } from '../test/inertia';

const posts = [
    {
        id: 9,
        title: 'Clinic advice for summer',
        slug: 'clinic-advice-summer',
        status: 'in_review',
        channel: 'apes_pet_care_clinic',
        updated_at: '2026-08-05T09:00:00Z',
        author: 'Alex Editor',
    },
    {
        id: 10,
        title: 'Shelter adoption update',
        slug: 'shelter-adoption-update',
        status: 'published',
        channel: 'apes_shelter_rescue',
        updated_at: null,
        author: 'Alex Editor',
    },
];

function page<T>(data: T[], overrides: Partial<{ current_page: number; last_page: number; next_page_url: string | null }> = {}) {
    return { data, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null, ...overrides };
}

describe('Soft glass staff posts workspace', () => {
    let desktopChangeListener: ((event: MediaQueryListEvent) => void) | undefined;

    beforeEach(() => {
        desktopChangeListener = undefined;
        Object.defineProperty(window, 'matchMedia', {
            configurable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches: false,
                media: query,
                onchange: null,
                addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
                    desktopChangeListener = listener;
                },
                removeEventListener: vi.fn(),
                addListener: vi.fn(),
                removeListener: vi.fn(),
                dispatchEvent: vi.fn(),
            })),
        });
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Editor', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true, reviewPosts: true },
            },
        });
    });

    it('groups navigation, links both panels and shows breadcrumbs', () => {
        render(<PostsIndex posts={page(posts)} filterStatus={null} canReview />);

        const nav = screen.getByRole('navigation', { name: 'Staff workspace' });
        expect(within(nav).getByText('Content')).toBeInTheDocument();
        expect(within(nav).getByText('Audience')).toBeInTheDocument();
        expect(within(nav).getByText('Commerce')).toBeInTheDocument();
        expect(within(nav).getByText('Insights')).toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: 'Posts' })).toHaveAttribute('aria-current', 'page');
        expect(within(nav).getByRole('link', { name: 'Review queue' })).toHaveAttribute('href', '/staff/posts/review');

        const panelSwitch = screen.getByRole('navigation', { name: 'Switch panel' });
        expect(within(panelSwitch).getByRole('link', { name: 'Staff' })).toHaveAttribute('aria-current', 'true');
        expect(within(panelSwitch).getByRole('link', { name: 'Admin' })).toHaveAttribute('href', '/admin/users');

        const breadcrumb = screen.getByRole('navigation', { name: 'Breadcrumb' });
        expect(within(breadcrumb).getByRole('link', { name: 'Staff' })).toHaveAttribute('href', '/staff/posts');
        expect(within(breadcrumb).getByText('Posts')).toHaveAttribute('aria-current', 'page');

        expect(screen.getByRole('heading', { level: 1, name: 'Posts' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View site' })).toHaveAttribute('href', '/');
        expect(screen.getByRole('main')).toHaveAttribute('id', 'main-content');
    });

    it('lists posts in a labelled table with status badges and pagination', () => {
        render(<PostsIndex posts={page(posts, { last_page: 3, next_page_url: '/staff/posts?page=2' })} filterStatus={null} canReview />);

        const table = screen.getByRole('table', { name: 'Newsroom posts' });
        expect(within(table).getByRole('link', { name: 'Edit Clinic advice for summer' })).toHaveAttribute('href', '/staff/posts/9/edit');
        expect(within(table).getByText('In review')).toHaveClass('sg-badge-warning');
        expect(within(table).getByText('Published')).toHaveClass('sg-badge-ok');
        expect(within(table).getAllByRole('cell', { name: /Pet Care Clinic/ })[0]).toHaveAttribute('data-label', 'Channel');

        const pager = screen.getByRole('navigation', { name: 'Posts pagination' });
        expect(pager).toHaveTextContent('Page 1 of 3');
        expect(within(pager).getByRole('link', { name: 'Next' })).toHaveAttribute('href', '/staff/posts?page=2');

        const filters = screen.getByRole('navigation', { name: 'Post status filters' });
        expect(within(filters).getByRole('link', { name: 'All' })).toHaveAttribute('aria-current', 'page');
        expect(within(filters).getByRole('link', { name: 'Drafts' })).toHaveAttribute('href', '/staff/posts?status=draft');
    });

    it('opens the navigation drawer as an accessible modal on small screens', async () => {
        const user = userEvent.setup();
        render(<PostsIndex posts={page(posts)} filterStatus={null} canReview />);

        const navigationButton = screen.getByRole('button', { name: 'Open workspace navigation' });
        await user.click(navigationButton);
        expect(navigationButton).toHaveAttribute('aria-expanded', 'true');

        const background = screen.getByTestId('workspace-background');
        const dialog = screen.getByRole('dialog', { name: 'Workspace navigation' });
        const closeButton = within(dialog).getByRole('button', { name: 'Close workspace navigation' });
        expect(screen.getAllByRole('navigation', { name: 'Staff workspace' })).toHaveLength(1);
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(background).toHaveAttribute('inert');
        expect(background).toHaveAttribute('aria-hidden', 'true');
        expect(document.body.style.overflow).toBe('hidden');
        expect(closeButton).toHaveFocus();

        await user.tab({ shift: true });
        expect(dialog).toContainElement(document.activeElement as HTMLElement);

        act(() => desktopChangeListener?.({ matches: true } as MediaQueryListEvent));
        expect(screen.queryByRole('dialog', { name: 'Workspace navigation' })).not.toBeInTheDocument();
        expect(background).not.toHaveAttribute('inert');
        expect(document.body.style.overflow).toBe('');
        expect(screen.getByRole('link', { name: 'Posts' })).toHaveFocus();

        await user.click(navigationButton);
        await user.keyboard('{Escape}');
        expect(navigationButton).toHaveAttribute('aria-expanded', 'false');
        expect(background).not.toHaveAttribute('inert');
        expect(navigationButton).toHaveFocus();
    });

    it('keeps admin and review navigation out of the staff-only workspace', () => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 2, name: 'Sam Staff', email: 'sam@example.test', role: 'staff' },
                can: { accessStaff: true, accessAdmin: false, reviewPosts: false },
            },
        });

        render(<PostsIndex posts={page([])} filterStatus={null} canReview={false} />);

        expect(screen.getByRole('navigation', { name: 'Staff workspace' })).toBeInTheDocument();
        expect(screen.queryByRole('navigation', { name: 'Switch panel' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Review queue' })).not.toBeInTheDocument();
        expect(screen.getByText('No posts found')).toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: 'New draft' })[0]).toHaveAttribute('href', '/staff/posts/new');
    });
});
