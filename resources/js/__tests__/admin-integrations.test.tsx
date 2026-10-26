import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import IntegrationsIndex, { type IntegrationProvider } from '../Pages/Admin/Settings/Integrations/Index';
import type { SharedPageProps } from '../types/page';
import { getInertiaMock, setMockPage } from '../test/inertia';

const superAdminProps: SharedPageProps = {
    appName: 'APES Newsroom',
    auth: {
        user: { id: 1, name: 'Sky Owner', email: 'sky@example.test', role: 'super_admin' },
        can: { accessStaff: true, accessAdmin: true, accessSuperAdmin: true },
    },
};

const stripe: IntegrationProvider = {
    provider: 'stripe',
    label: 'Stripe',
    description: 'Membership billing.',
    managed: true,
    configured: true,
    keys: [
        {
            key: 'secret',
            label: 'Secret key',
            help: 'Starts with sk_test_ or sk_live_.',
            sensitive: true,
            source: 'database',
            last_four: '9876',
            value: null,
            set_at: '2026-10-26T12:00:00+00:00',
            set_by: 'Sky Owner',
            rotated_at: null,
        },
        {
            key: 'webhook_secret',
            label: 'Webhook signing secret',
            help: 'Starts with whsec_.',
            sensitive: true,
            source: null,
            last_four: null,
            value: null,
            set_at: null,
            set_by: null,
            rotated_at: null,
        },
    ],
    latest: {
        id: 7,
        status: 'warn',
        message: 'Test mode. Secret key accepted, but no enabled webhook endpoint points at /stripe/webhook.',
        duration_ms: 120,
        trigger: 'schedule',
        checked_at: '2026-10-26T12:00:00+00:00',
        user: null,
    },
    history: [],
    rotations: [{ id: 3, provider: 'stripe', action: 'set', key_label: 'Secret key', last_four: '9876', actor: 'Sky Owner', at: '2026-10-26T12:00:00+00:00' }],
};

describe('Admin integrations page', () => {
    beforeEach(() => {
        setMockPage(superAdminProps);
    });

    it('shows only the last four characters and the latest status', () => {
        render(<IntegrationsIndex providers={[stripe]} canManage />);

        const card = screen.getByRole('region', { name: 'Stripe' });
        expect(within(card).getByText('••••9876')).toBeInTheDocument();
        expect(within(card).getAllByText('Warning').length).toBeGreaterThan(0);
        expect(within(screen.getByRole('region', { name: 'Status overview' })).getByText('Warning')).toBeInTheDocument();
    });

    it('uses write-only password inputs and submits with PUT', async () => {
        const user = userEvent.setup();
        render(<IntegrationsIndex providers={[stripe]} canManage />);

        const input = screen.getByLabelText('New Stripe webhook signing secret');
        expect(input).toHaveAttribute('type', 'password');
        expect(input).toHaveValue('');

        await user.type(input, 'whsec_newvalue');
        await user.click(within(screen.getByRole('region', { name: 'Stripe' })).getAllByRole('button', { name: 'Save' })[0]);

        const [visit] = getInertiaMock().visits;
        expect(visit.url).toBe('/admin/settings/integrations/stripe/webhook_secret');
        expect(visit.data).toEqual({ secret_value: 'whsec_newvalue', force: false });

        act(() => visit.options.onSuccess?.({ props: superAdminProps }));
        expect(input).toHaveValue('');
    });

    it('offers Save anyway after a failed connection test', async () => {
        const user = userEvent.setup();
        render(<IntegrationsIndex providers={[stripe]} canManage failedCheck={{ provider: 'stripe', key: 'webhook_secret' }} />);

        await user.type(screen.getByLabelText('New Stripe webhook signing secret'), 'whsec_bad');
        await user.click(within(screen.getByRole('region', { name: 'Stripe' })).getAllByRole('button', { name: 'Save' })[0]);
        const [visit] = getInertiaMock().visits;
        act(() => visit.options.onError?.({ secret_value: 'Connection test failed: Stripe rejected the secret key.' }));

        expect(screen.getByText('Connection test failed: Stripe rejected the secret key.')).toBeInTheDocument();
        expect(screen.getByLabelText('Save anyway, even though the connection test failed')).not.toBeChecked();
    });

    it('hides key inputs from admins who cannot manage keys', () => {
        render(<IntegrationsIndex providers={[stripe]} canManage={false} />);

        expect(screen.queryByLabelText('New Stripe secret key')).not.toBeInTheDocument();
        expect(screen.getByText('You can view status and test connections. Only super admins can change keys.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Test connection' })).toBeEnabled();
    });

    it('marks the Integrations navigation link as current', () => {
        render(<IntegrationsIndex providers={[stripe]} canManage />);

        const nav = screen.getByRole('navigation', { name: 'Admin workspace' });
        expect(within(nav).getByRole('link', { name: 'Integrations' })).toHaveAttribute('aria-current', 'page');
    });
});
