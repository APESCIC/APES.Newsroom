import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import UsersIndex, { type UserFilters } from '../Pages/Admin/Users/Index';
import UsersShow from '../Pages/Admin/Users/Show';
import type { UserDetail, UserRow } from '../Pages/Admin/Users/types';
import { getInertiaMock, setMockPage } from '../test/inertia';

const filters: UserFilters = { q: '', role: '', method: '', status: '', security: '' };

const row: UserRow = {
    id: 9,
    name: 'Paula Password',
    email: 'paula@example.test',
    role: 'public',
    sign_in_method: 'password',
    email_verified: true,
    two_factor_enabled: true,
    passkeys_count: 2,
    locked: false,
    created_at: '2026-10-01T10:00:00+00:00',
};

const detail: UserDetail = {
    ...row,
    lock: null,
    can: { manage_security: true, change_role: false },
    email_verified_at: '2026-10-01T10:00:00+00:00',
    sso_managed: false,
    role_source: { type: 'in_app', groups: [] },
    recovery_codes_remaining: 6,
    passkeys: [{ id: 1, name: 'Laptop', created_at: null, last_used_at: null }],
    sessions: [{ id: 3, browser: 'Firefox', platform: 'Windows', device_type: 'desktop', ip_address: '198.51.100.7', remembered: true, last_seen_at: null }],
    api_tokens: [],
    security_events: [{ id: 4, action: 'account.password_changed', created_at: null, actor: null, ip_address: null, payload: [] }],
    login_history: [
        { id: 5, action: 'auth.failed', result: 'failure', method: null, created_at: null, ip_address: '198.51.100.1', browser: 'Firefox', platform: 'Windows', device_type: 'desktop' },
        { id: 6, action: 'auth.passkey_login', result: 'success', method: 'passkey', created_at: null, ip_address: '198.51.100.2', browser: null, platform: null, device_type: null },
    ],
};

describe('Admin users pages', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
        });
    });

    it('lists users with their sign-in method and second factors', () => {
        render(<UsersIndex filters={filters} roles={['public', 'staff', 'admin', 'super_admin']} users={{ data: [row], current_page: 1, last_page: 1, per_page: 25, total: 1, prev_page_url: null, next_page_url: null }} />);

        const table = screen.getByRole('table');
        expect(within(table).getByRole('link', { name: 'Paula Password' })).toHaveAttribute('href', '/admin/users/9');
        expect(within(table).getByText('Password')).toBeInTheDocument();
        expect(within(table).getByText('App, 2 passkeys')).toBeInTheDocument();
        const nav = screen.getByRole('navigation', { name: 'Admin workspace' });
        expect(within(nav).getByRole('link', { name: 'Users' })).toHaveAttribute('aria-current', 'page');
    });

    it('filters by role and second factor', async () => {
        const user = userEvent.setup();
        render(<UsersIndex filters={filters} roles={['public', 'staff']} users={{ data: [], current_page: 1, last_page: 1, per_page: 25, total: 0, prev_page_url: null, next_page_url: null }} />);

        await user.selectOptions(screen.getByLabelText('Role'), 'staff');
        await user.selectOptions(screen.getByLabelText('Second factor'), 'none');
        await user.click(screen.getByRole('button', { name: 'Apply filters' }));

        expect(getInertiaMock().get).toHaveBeenCalledWith('/admin/users', { role: 'staff', security: 'none' }, expect.anything());
        expect(screen.getByText('No users match these filters.')).toBeInTheDocument();
    });

    it('shows account, second factor, sessions and events on the detail page', () => {
        render(<UsersShow user={detail} />);

        expect(screen.getByRole('heading', { name: 'Paula Password', level: 1 })).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Account' })).getByText('Set in the Newsroom')).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Second factor' })).getByText('On (6 recovery codes left)')).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Active sessions' })).getByText(/Firefox on Windows/)).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Admin API tokens' })).getByText('No tokens.')).toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Recent security events' })).getByText('account.password_changed')).toBeInTheDocument();
    });

    it('shows sign-in history with result, method, IP and device', () => {
        render(<UsersShow user={detail} />);

        const history = within(screen.getByRole('region', { name: 'Sign-in history' }));
        const rows = history.getAllByRole('row');
        expect(rows).toHaveLength(3);
        expect(within(rows[1]).getByText('Failed')).toBeInTheDocument();
        expect(within(rows[1]).getByText('Firefox on Windows')).toBeInTheDocument();
        expect(within(rows[2]).getByText('passkey_login')).toBeInTheDocument();
        expect(within(rows[2]).getByText('passkey')).toBeInTheDocument();
        expect(within(rows[2]).getByText('198.51.100.2')).toBeInTheDocument();
    });

    it('locks an account after asking for a reason', async () => {
        const user = userEvent.setup();
        render(<UsersShow user={detail} />);

        const access = within(screen.getByRole('region', { name: 'Account access' }));
        expect(access.getByText('Active. The account can sign in.')).toBeInTheDocument();
        await user.click(access.getByRole('button', { name: 'Lock account' }));

        const dialog = within(screen.getByRole('dialog', { name: 'Lock this account?' }));
        await user.type(dialog.getByLabelText('Reason (recorded in the audit log)'), 'Suspicious sign-ins');
        await user.click(dialog.getByRole('button', { name: 'Lock account' }));

        expect(getInertiaMock().post).toHaveBeenCalledWith('/admin/users/9/lock', { reason: 'Suspicious sign-ins' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('shows lock details and offers unlock for a locked account', () => {
        render(
            <UsersShow
                user={{ ...detail, locked: true, lock: { locked_at: null, reason: 'Phished', by: { id: 1, name: 'Alex Admin' } } }}
            />,
        );

        const access = within(screen.getByRole('region', { name: 'Account access' }));
        expect(access.getByText(/by Alex Admin · Phished/)).toBeInTheDocument();
        expect(access.getByRole('button', { name: 'Unlock account' })).toBeInTheDocument();
        expect(access.queryByRole('button', { name: 'Lock account' })).not.toBeInTheDocument();
    });

    it('hides security actions the admin may not take', () => {
        render(<UsersShow user={{ ...detail, can: { manage_security: false, change_role: false } }} />);

        const access = within(screen.getByRole('region', { name: 'Account access' }));
        expect(access.queryByRole('button')).not.toBeInTheDocument();
        expect(access.getByText('You cannot change security settings for this account.')).toBeInTheDocument();
    });

    it('explains that SSO accounts get their second factor from Cloudron', () => {
        render(<UsersShow user={{ ...detail, sso_managed: true, sign_in_method: 'sso', role: 'staff', role_source: { type: 'directory', groups: ['newsroom-staff'] } }} />);

        expect(screen.getByText('Managed by Cloudron SSO, which enforces multi-factor sign-in.')).toBeInTheDocument();
        expect(screen.getByText('Directory groups: newsroom-staff')).toBeInTheDocument();
    });
});
