import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PostsIndex from '../Pages/Staff/Posts/Index';
import { setMockPage } from '../test/inertia';

describe('Glass Studio staff posts workspace', () => {
    let desktopChangeListener: ((event: MediaQueryListEvent) => void) | undefined;
    let resizeObserverCallback: ResizeObserverCallback | undefined;

    beforeEach(() => {
        desktopChangeListener = undefined;
        resizeObserverCallback = undefined;
        vi.stubGlobal(
            'ResizeObserver',
            class {
                constructor(callback: ResizeObserverCallback) {
                    resizeObserverCallback = callback;
                }

                observe = vi.fn();
                disconnect = vi.fn();
                unobserve = vi.fn();
            },
        );
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
                can: { accessStaff: true, accessAdmin: true },
            },
        });
    });

    it('shows role-aware workspace navigation, filters, table and mobile cards', async () => {
        const user = userEvent.setup();
        render(
            <PostsIndex
                posts={[
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
                ]}
                filterStatus={null}
                canReview
            />,
        );

        expect(screen.getByRole('navigation', { name: 'Staff workspace' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Admin panel' })).toHaveAttribute('href', '/admin/moderation');
        expect(screen.getByRole('link', { name: 'Review queue' })).toHaveAttribute('href', '/staff/posts/review');
        expect(screen.getByRole('link', { name: 'New draft' })).toHaveAttribute('href', '/staff/posts/new');
        expect(screen.getByRole('navigation', { name: 'Post status filters' })).toHaveClass('workspace-glass-tabs');
        expect(screen.getByRole('table', { name: 'Newsroom posts' }).closest('div')).toHaveClass('workspace-glass-panel');
        expect(screen.getByRole('list', { name: 'Newsroom posts on small screens' })).toBeInTheDocument();
        expect(screen.getAllByText('In review').length).toBeGreaterThan(1);
        expect(screen.getAllByText('Published').length).toBeGreaterThan(1);
        expect(screen.getAllByText('Pet Care Clinic').length).toBeGreaterThan(1);
        expect(screen.getByRole('link', { name: 'Edit Clinic advice for summer' })).toHaveAttribute(
            'href',
            '/staff/posts/9/edit',
        );

        expect(screen.getByTestId('workspace-shell')).toHaveClass('workspace-gradient-shell');
        expect(screen.getByTestId('workspace-sidebar')).toHaveClass('workspace-glass-rail');
        expect(screen.getByTestId('workspace-task-header')).toHaveClass('workspace-glass-chrome');
        expect(screen.getByTestId('workspace-canvas')).toHaveClass('workspace-canvas');

        const taskHeader = screen.getByTestId('workspace-task-header');
        vi.spyOn(taskHeader, 'getBoundingClientRect').mockReturnValue({ height: 176 } as DOMRect);
        act(() => resizeObserverCallback?.([], {} as ResizeObserver));
        expect(screen.getByTestId('workspace-shell')).toHaveStyle({ '--workspace-task-header-height': '176px' });

        const navigationButton = screen.getByRole('button', { name: 'Open workspace navigation' });
        await user.click(navigationButton);
        expect(navigationButton).toHaveAttribute('aria-expanded', 'true');

        const background = screen.getByTestId('workspace-background');
        const dialog = screen.getByRole('dialog', { name: 'Workspace navigation' });
        const closeButton = within(dialog).getByRole('button', { name: 'Close workspace navigation' });
        expect(screen.getAllByRole('navigation', { name: 'Staff workspace' })).toHaveLength(1);
        expect(within(dialog).getByRole('navigation', { name: 'Staff workspace' })).toBeInTheDocument();
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(background).toHaveAttribute('inert');
        expect(background).toHaveAttribute('aria-hidden', 'true');
        expect(document.body.style.overflow).toBe('hidden');
        expect(within(dialog).getByTestId('workspace-sidebar')).toHaveClass('overflow-y-auto');
        expect(closeButton).toHaveFocus();

        await user.tab({ shift: true });
        expect(dialog).toContainElement(document.activeElement as HTMLElement);
        await user.tab();
        expect(closeButton).toHaveFocus();

        act(() => desktopChangeListener?.({ matches: true } as MediaQueryListEvent));
        expect(screen.queryByRole('dialog', { name: 'Workspace navigation' })).not.toBeInTheDocument();
        expect(background).not.toHaveAttribute('inert');
        expect(background).not.toHaveAttribute('aria-hidden');
        expect(document.body.style.overflow).toBe('');
        expect(screen.getByRole('link', { name: 'Posts' })).toHaveFocus();

        await user.click(navigationButton);
        await user.keyboard('{Escape}');
        expect(navigationButton).toHaveAttribute('aria-expanded', 'false');
        expect(background).not.toHaveAttribute('inert');
        expect(background).not.toHaveAttribute('aria-hidden');
        expect(document.body.style.overflow).toBe('');
        expect(navigationButton).toHaveFocus();
    });

    it('keeps admin and review navigation out of the staff-only workspace', () => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 2, name: 'Sam Staff', email: 'sam@example.test', role: 'staff' },
                can: { accessStaff: true, accessAdmin: false },
            },
        });

        render(<PostsIndex posts={[]} filterStatus={null} canReview={false} />);

        expect(screen.getByRole('navigation', { name: 'Staff workspace' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Admin panel' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Review queue' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'New draft' })).toHaveAttribute('href', '/staff/posts/new');
    });
});
