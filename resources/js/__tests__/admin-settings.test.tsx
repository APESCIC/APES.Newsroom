import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
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

    it('shows the saved flash message', () => {
        setMockPage({ ...adminProps, flash: { status: 'Settings saved.' } });
        render(<SettingsIndex settings={[editorSetting]} />);

        expect(screen.getByRole('status')).toHaveTextContent('Settings saved.');
    });
});
