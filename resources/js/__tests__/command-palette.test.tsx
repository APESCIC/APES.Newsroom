import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import WorkspaceLayout from '../Components/Layout/WorkspaceLayout';
import { commandsFor, filterCommands } from '../Components/Workspace/CommandPalette';
import { getInertiaMock, setMockPage } from '../test/inertia';

const staffCan = { accessStaff: true, accessAdmin: false, reviewPosts: false };
const adminCan = { accessStaff: true, accessAdmin: true, reviewPosts: true };

function renderWorkspace() {
    return render(
        <WorkspaceLayout area="Staff" active="posts" title="Posts">
            <p>Body</p>
        </WorkspaceLayout>,
    );
}

describe('command palette', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' }, can: adminCan },
        });
    });

    it('never offers admin pages or the review queue to staff', () => {
        const hrefs = commandsFor(staffCan).map((command) => command.href);

        expect(hrefs).toContain('/staff/posts/new');
        expect(hrefs).toContain('/staff/newsletters');
        expect(hrefs.some((href) => href.startsWith('/admin'))).toBe(false);
        expect(hrefs).not.toContain('/staff/posts/review');
    });

    it('offers both panels, quick actions and the review queue to admins', () => {
        const commands = commandsFor(adminCan);

        expect(commands.map((command) => command.href)).toEqual(expect.arrayContaining(['/admin/webhooks', '/staff/posts/review', '/staff/posts/new']));
        expect(filterCommands(commands, 'dkim').map((command) => command.label)).toEqual(['Sending domains']);
        expect(filterCommands(commands, 'admin webhooks').map((command) => command.label)).toEqual(['Webhooks']);
    });

    it('opens with Ctrl+K, runs the highlighted result and closes on Escape', async () => {
        const user = userEvent.setup();
        renderWorkspace();
        const searchPill = screen.getByRole('button', { name: 'Search' });
        expect(searchPill).toHaveAttribute('aria-keyshortcuts', 'Control+K Meta+K');
        searchPill.focus();

        await user.keyboard('{Control>}k{/Control}');
        const dialog = screen.getByRole('dialog', { name: 'Search and run commands' });
        const input = within(dialog).getByRole('combobox', { name: 'Search pages and actions' });
        expect(input).toHaveFocus();
        expect(screen.getByTestId('workspace-background')).toHaveAttribute('inert');

        await user.type(input, 'webhook');
        const options = within(dialog).getAllByRole('option');
        expect(options).toHaveLength(1);
        expect(options[0]).toHaveAttribute('aria-selected', 'true');
        expect(input).toHaveAttribute('aria-activedescendant', options[0].id);

        await user.keyboard('{Enter}');
        expect(getInertiaMock().visit).toHaveBeenCalledWith('/admin/webhooks');
        expect(screen.queryByRole('dialog', { name: 'Search and run commands' })).not.toBeInTheDocument();

        await user.click(searchPill);
        expect(screen.getByRole('dialog', { name: 'Search and run commands' })).toBeInTheDocument();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog', { name: 'Search and run commands' })).not.toBeInTheDocument();
        expect(searchPill).toHaveFocus();
    });

    it('keeps focus inside the palette and moves the selection with the arrow keys', async () => {
        const user = userEvent.setup();
        renderWorkspace();

        await user.keyboard('{Meta>}k{/Meta}');
        const dialog = screen.getByRole('dialog', { name: 'Search and run commands' });
        const input = within(dialog).getByRole('combobox');

        await user.tab();
        expect(input).toHaveFocus();
        await user.tab({ shift: true });
        expect(input).toHaveFocus();

        await user.keyboard('{ArrowDown}');
        expect(within(dialog).getAllByRole('option')[1]).toHaveAttribute('aria-selected', 'true');
        await user.keyboard('{ArrowUp}{ArrowUp}');
        const options = within(dialog).getAllByRole('option');
        expect(options[options.length - 1]).toHaveAttribute('aria-selected', 'true');

        await user.type(input, 'zzz-nothing');
        expect(within(dialog).getByText('No matching pages or actions.')).toBeInTheDocument();
    });

    it('shows a staff user no admin results', async () => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: { id: 2, name: 'Sam Staff', email: 'sam@example.test', role: 'staff' }, can: staffCan },
        });
        const user = userEvent.setup();
        renderWorkspace();

        await user.keyboard('{Control>}k{/Control}');
        const dialog = screen.getByRole('dialog', { name: 'Search and run commands' });
        await user.type(within(dialog).getByRole('combobox'), 'audit');
        expect(within(dialog).queryAllByRole('option')).toHaveLength(0);
        expect(within(dialog).queryByText(/Admin ·/)).not.toBeInTheDocument();
    });
});
