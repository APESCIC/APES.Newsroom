import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import ApiTokensIndex, { type ApiTokenRow } from '../Pages/Admin/ApiTokens/Index';
import { getInertiaMock, setMockPage } from '../test/inertia';

const owners = [
    { id: 1, name: 'Alex Admin', email: 'alex@example.test' },
    { id: 2, name: 'Sam Staff', email: 'sam@example.test' },
];
const abilities = [
    { value: 'posts:read', label: 'Read posts' },
    { value: 'posts:write', label: 'Create and edit posts' },
    { value: 'posts:publish', label: 'Publish and unpublish posts' },
];
const token: ApiTokenRow = {
    id: 5,
    name: 'Zapier',
    owner: owners[1],
    abilities: ['posts:read'],
    status: 'active',
    created_at: '2026-10-01T10:00:00+00:00',
    last_used_at: null,
    expires_at: '2026-12-30T10:00:00+00:00',
    revoked_at: null,
};

function renderPage(overrides: Partial<Parameters<typeof ApiTokensIndex>[0]> = {}) {
    return render(<ApiTokensIndex tokens={[token]} owners={owners} abilities={abilities} expiryDays={[30, 90, 180, 365]} created={null} {...overrides} />);
}

describe('Admin API tokens page', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' }, can: { accessStaff: true, accessAdmin: true } },
        });
    });

    it('lists tokens with owner, abilities, status and expiry but no secret', () => {
        renderPage();

        const row = screen.getAllByRole('row')[1];
        expect(within(row).getByText('Zapier')).toBeInTheDocument();
        expect(within(row).getByText('Sam Staff')).toBeInTheDocument();
        expect(within(row).getByText('posts:read')).toBeInTheDocument();
        expect(within(row).getByText('Active')).toBeInTheDocument();
        expect(within(row).getByText('Never')).toBeInTheDocument();
    });

    it('shows a newly created token once', () => {
        renderPage({ created: { id: 6, name: 'CI', token: 'nr_admin_abc123' } });

        expect(within(screen.getByRole('region', { name: 'New token' })).getByText('nr_admin_abc123')).toBeInTheDocument();
    });

    it('creates a token with the chosen owner, abilities and expiry', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.type(screen.getByLabelText('Name'), 'Zapier');
        await user.selectOptions(screen.getByLabelText('Owner'), '2');
        await user.click(screen.getByLabelText(/Publish and unpublish posts/));
        await user.selectOptions(screen.getByLabelText('Expires after'), '30');
        await user.click(screen.getByRole('button', { name: 'Create token' }));

        const [visit] = getInertiaMock().visits;
        expect(visit.url).toBe('/admin/api-tokens');
        expect(visit.data).toEqual({ name: 'Zapier', owner_id: 2, abilities: ['posts:read', 'posts:write'], expires_in_days: 30 });
    });

    it('revokes a token after asking for a reason', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Revoke' }));
        const dialog = within(screen.getByRole('dialog', { name: 'Revoke "Zapier"?' }));
        await user.type(dialog.getByLabelText('Reason (recorded in the audit log)'), 'Leaked');
        await user.click(dialog.getByRole('button', { name: 'Revoke token' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/admin/api-tokens/5');
        expect(visit?.data).toEqual({ reason: 'Leaked' });
    });

    it('hides revoke for tokens already revoked', () => {
        renderPage({ tokens: [{ ...token, status: 'revoked', revoked_at: '2026-10-10T10:00:00+00:00' }] });

        expect(screen.getByText('Revoked')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Revoke' })).not.toBeInTheDocument();
    });
});
