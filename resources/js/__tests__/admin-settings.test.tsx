import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import WorkspaceLayout from '../Components/Layout/WorkspaceLayout';
import SettingsIndex, { type SettingDefinition } from '../Pages/Admin/Settings/Index';
import type { SharedPageProps } from '../types/page';
import { getInertiaMock, setMockPage } from '../test/inertia';

const adminProps: SharedPageProps = {
    appName: 'APES Newsroom',
    auth: {
        user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
        can: { accessStaff: true, accessAdmin: true },
    },
};

const editorSetting: SettingDefinition = {
    key: 'editor.driver',
    section: 'Editor',
    min: null,
    max: null,
    unit: null,
    label: 'Staff editor',
    help: 'Rich-text editor used on staff post and page screens.',
    type: 'enum',
    options: [
        { value: 'editorjs', label: 'Editor.js' },
        { value: 'tinymce', label: 'TinyMCE' },
    ],
    value: 'editorjs',
};

describe('Admin settings page', () => {
    beforeEach(() => {
        setMockPage(adminProps);
    });

    it('renders declared settings with current values and help text', () => {
        render(<SettingsIndex settings={[editorSetting]} />);

        const group = screen.getByRole('group', { name: 'Staff editor' });
        expect(group).toHaveAccessibleDescription('Rich-text editor used on staff post and page screens.');
        expect(screen.getByRole('radio', { name: 'Editor.js' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'TinyMCE' })).not.toBeChecked();
    });

    it('submits nested settings with PUT', async () => {
        const user = userEvent.setup();
        render(<SettingsIndex settings={[editorSetting]} />);

        await user.click(screen.getByRole('radio', { name: 'TinyMCE' }));
        await user.click(screen.getByRole('button', { name: 'Save settings' }));

        const [visit] = getInertiaMock().visits;
        expect(getInertiaMock().put).toHaveBeenCalledTimes(1);
        expect(visit.url).toBe('/admin/settings');
        expect(visit.data).toEqual({ settings: { editor: { driver: 'tinymce' } } });
    });

    it('shows server validation errors inline', async () => {
        const user = userEvent.setup();
        render(<SettingsIndex settings={[editorSetting]} />);

        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        const [visit] = getInertiaMock().visits;
        act(() => {
            visit.options.onError?.({ 'settings.editor.driver': 'The selected staff editor is invalid.' });
        });

        expect(screen.getByText('The selected staff editor is invalid.')).toBeInTheDocument();
        expect(screen.getByRole('group', { name: 'Staff editor' })).toHaveAttribute('aria-invalid', 'true');
    });

    it('marks the Settings navigation link as the current page', () => {
        render(<SettingsIndex settings={[editorSetting]} />);

        const nav = screen.getByRole('navigation', { name: 'Admin workspace' });
        expect(within(nav).getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/admin/settings');
        expect(within(nav).getByRole('link', { name: 'Settings' })).toHaveAttribute('aria-current', 'page');
        expect(within(nav).getByRole('link', { name: 'Releases' })).not.toHaveAttribute('aria-current');
    });

    it('shows the Settings link to admins on other admin screens without marking it current', () => {
        render(
            <WorkspaceLayout area="Admin" active="releases" title="Releases">
                <p>Releases</p>
            </WorkspaceLayout>,
        );

        const nav = screen.getByRole('navigation', { name: 'Admin workspace' });
        expect(within(nav).getByRole('link', { name: 'Settings' })).not.toHaveAttribute('aria-current');
    });

    it('hides the Settings link from staff and members', () => {
        for (const role of ['staff', 'public'] as const) {
            setMockPage({
                appName: 'APES Newsroom',
                auth: {
                    user: { id: 2, name: 'Sam', email: 'sam@example.test', role },
                    can: { accessStaff: role === 'staff', accessAdmin: false },
                },
            });
            const { unmount } = render(
                <WorkspaceLayout area="Admin" active="settings" title="Settings">
                    <p>Settings</p>
                </WorkspaceLayout>,
            );

            expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
            unmount();
        }
    });

    it('renders number, checkbox and multi-choice settings in their sections and submits typed values', async () => {
        const user = userEvent.setup();
        const base = { section: 'Security', help: 'Help text.', options: [], min: null, max: null, unit: null };
        const settings: SettingDefinition[] = [
            editorSetting,
            { ...base, key: 'security.password.min_length', label: 'Minimum password length', type: 'int', value: 12, min: 12, max: 128, unit: 'characters' },
            { ...base, key: 'security.magic_links.enabled', label: 'Allow email sign-in links', type: 'bool', value: true },
            {
                ...base,
                key: 'security.two_factor.required_roles',
                label: 'Roles that must use two-factor sign-in',
                type: 'multi_enum',
                value: [],
                options: [
                    { value: 'public', label: 'Public' },
                    { value: 'staff', label: 'Staff' },
                ],
            },
        ];
        render(<SettingsIndex settings={settings} />);

        expect(screen.getByRole('heading', { name: 'Security' })).toBeInTheDocument();
        expect(screen.getByText('Between 12 and 128.')).toBeInTheDocument();

        const length = screen.getByLabelText('Minimum password length');
        await user.clear(length);
        await user.type(length, '16');
        await user.click(screen.getByLabelText('Allow email sign-in links'));
        await user.click(within(screen.getByRole('group', { name: 'Roles that must use two-factor sign-in' })).getByLabelText('Public'));
        await user.click(screen.getByRole('button', { name: 'Save settings' }));

        const [visit] = getInertiaMock().visits;
        expect(visit.data).toEqual({
            settings: {
                editor: { driver: 'editorjs' },
                security: { password: { min_length: 16 }, magic_links: { enabled: false }, two_factor: { required_roles: ['public'] } },
            },
        });
    });

    it('shows the saved flash message', () => {
        setMockPage({ ...adminProps, flash: { status: 'Settings saved.' } });
        render(<SettingsIndex settings={[editorSetting]} />);

        expect(screen.getByRole('status')).toHaveTextContent('Settings saved.');
    });
});
