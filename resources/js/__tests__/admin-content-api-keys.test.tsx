import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import ContentApiKeysIndex, { type ContentApiKeyRow } from '../Pages/Admin/ContentApiKeys/Index';
import { getInertiaMock, setMockPage } from '../test/inertia';

const key: ContentApiKeyRow = {
    id: 4,
    name: 'Partner site',
    prefix: 'nr_content_Ab12',
    created_by: 'Alex Admin',
    created_at: '2026-10-01T10:00:00+00:00',
    last_used_at: null,
    revoked_at: null,
};

describe('Admin Content API keys page', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' }, can: { accessStaff: true, accessAdmin: true } },
        });
    });

    it('lists keys by prefix and explains the environment fallback', () => {
        render(<ContentApiKeysIndex keys={[key]} environmentKeyConfigured created={null} />);

        const row = screen.getAllByRole('row')[1];
        expect(within(row).getByText('nr_content_Ab12…')).toBeInTheDocument();
        expect(within(row).getByText('Active')).toBeInTheDocument();
        expect(screen.getByText(/environment key is still accepted/)).toBeInTheDocument();
    });

    it('shows a new key once and creates keys by name', async () => {
        const user = userEvent.setup();
        render(<ContentApiKeysIndex keys={[]} environmentKeyConfigured={false} created={{ id: 5, name: 'CI', key: 'nr_content_secret' }} />);

        expect(within(screen.getByRole('region', { name: 'New key' })).getByText('nr_content_secret')).toBeInTheDocument();

        await user.type(screen.getByLabelText('Key name'), 'Mobile app');
        await user.click(screen.getByRole('button', { name: 'Create key' }));
        expect(getInertiaMock().visits[0].data).toEqual({ name: 'Mobile app' });
    });

    it('revokes a key with a reason', async () => {
        const user = userEvent.setup();
        render(<ContentApiKeysIndex keys={[key]} environmentKeyConfigured={false} created={null} />);

        await user.click(screen.getByRole('button', { name: 'Revoke' }));
        const dialog = within(screen.getByRole('dialog', { name: 'Revoke "Partner site"?' }));
        await user.type(dialog.getByLabelText('Reason (recorded in the audit log)'), 'Contract ended');
        await user.click(dialog.getByRole('button', { name: 'Revoke key' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/admin/content-api-keys/4');
        expect(visit?.data).toEqual({ reason: 'Contract ended' });
    });
});
