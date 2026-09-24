import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import ModerationIndex from '../Pages/Admin/Moderation/Index';
import { getInertiaMock, setMockPage } from '../test/inertia';

describe('Glass Studio admin moderation workspace', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
        });
    });

    it('exposes complete keyboard-operated queues and preserves every moderation payload', async () => {
        const user = userEvent.setup();

        render(
            <ModerationIndex
                profiles={[{ id: 2, display_name: 'Sam Keeper', bio: 'Wildlife carer', user_name: 'sam', updated_at: null }]}
                comments={[
                    {
                        id: 3,
                        body: 'A thoughtful comment.',
                        user_name: 'reader',
                        post_title: 'Conservation update',
                        post_slug: 'conservation-update',
                        created_at: null,
                    },
                ]}
                reports={[{ id: 4, reason: 'Needs review', reportable_type: 'Comment', reportable_id: 3, reporter: 'member', created_at: null }]}
                suspended={[{ id: 5, display_name: 'Taylor', user_name: 'taylor', notes: 'Review requested' }]}
            />,
        );

        expect(screen.getByRole('img', { name: 'APES Newsroom' })).toHaveAttribute(
            'src',
            '/brand/apes-logo-compact.png',
        );
        expect(screen.getByRole('navigation', { name: 'Admin workspace' })).toBeInTheDocument();
        const profileSummary = screen.getByRole('button', { name: 'Profiles awaiting review: 1' });
        const commentSummary = screen.getByRole('button', { name: 'Comments awaiting review: 1' });
        expect(profileSummary).toHaveAttribute('aria-pressed', 'true');
        expect(commentSummary).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByRole('button', { name: 'Open reports: 1' })).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByRole('button', { name: 'Suspended profiles: 1' })).toHaveAttribute('aria-pressed', 'false');

        const tabs = screen.getByRole('tablist', { name: 'Moderation queues' });
        expect(tabs).toBeInTheDocument();
        const allPanels = screen.getAllByRole('tabpanel', { hidden: true });
        expect(allPanels).toHaveLength(4);
        for (const queue of ['profiles', 'comments', 'reports', 'suspended']) {
            const tab = screen.getByRole('tab', { name: new RegExp(queue, 'i') });
            const panel = document.getElementById(`panel-${queue}`);
            expect(tab).toHaveAttribute('aria-controls', `panel-${queue}`);
            expect(panel).toHaveAttribute('aria-labelledby', `tab-${queue}`);
            expect(panel?.hidden).toBe(queue !== 'profiles');
        }

        const profileTab = screen.getByRole('tab', { name: /Profiles/ });
        expect(profileTab).toHaveAttribute('tabindex', '0');
        expect(screen.getByRole('tab', { name: /Comments/ })).toHaveAttribute('tabindex', '-1');

        await user.click(screen.getByRole('button', { name: 'Approve profile for Sam Keeper' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/profiles/2', { status: 'approved' });
        await user.click(screen.getByRole('button', { name: 'Reject profile for Sam Keeper' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/profiles/2', { status: 'rejected' });
        await user.click(screen.getByRole('button', { name: 'Suspend profile for Sam Keeper' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/profiles/2', { status: 'suspended' });

        profileTab.focus();
        await user.keyboard('{ArrowRight}');
        const commentsTab = screen.getByRole('tab', { name: /Comments/ });
        expect(commentsTab).toHaveAttribute('aria-selected', 'true');
        expect(commentsTab).toHaveAttribute('tabindex', '0');
        expect(profileTab).toHaveAttribute('tabindex', '-1');
        expect(commentSummary).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByText('A thoughtful comment.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Approve comment by reader' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/comments/3', { status: 'approved' });
        await user.click(screen.getByRole('button', { name: 'Reject comment by reader' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/comments/3', { status: 'rejected' });

        await user.click(screen.getByRole('tab', { name: /Reports/ }));
        expect(screen.getByText('Needs review')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Resolve report 4' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/reports/4', { status: 'resolved' });
        await user.click(screen.getByRole('button', { name: 'Dismiss report 4' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/reports/4', { status: 'dismissed' });

        await user.click(screen.getByRole('tab', { name: /Suspended/ }));
        await user.click(screen.getByRole('button', { name: 'Lift suspension for Taylor' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/moderation/profiles/5', { status: 'private' });

        screen.getByRole('tab', { name: /Suspended/ }).focus();
        await user.keyboard('{Home}');
        expect(profileTab).toHaveFocus();
        expect(profileTab).toHaveAttribute('aria-selected', 'true');
        await user.keyboard('{End}');
        expect(screen.getByRole('tab', { name: /Suspended/ })).toHaveFocus();
    });
});
