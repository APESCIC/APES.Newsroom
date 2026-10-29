import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MailSendingIndex, { isTransportTestResult, type TransportOption } from '../Pages/Admin/Settings/MailSending/Index';
import type { SettingDefinition } from '../Pages/Admin/Settings/Index';
import { getInertiaMock, setMockPage } from '../test/inertia';

const settings: SettingDefinition[] = [
    {
        key: 'mail.system_transport',
        section: 'Mail sending',
        label: 'System and account mail',
        help: 'Sign-in links.',
        type: 'enum',
        value: 'cloudron_smtp',
        options: [
            { value: 'cloudron_smtp', label: 'Cloudron SMTP' },
            { value: 'mailgun_api', label: 'Mailgun API' },
            { value: 'custom_smtp', label: 'Custom SMTP' },
        ],
        min: null,
        max: null,
        unit: null,
    },
    { key: 'mail.soft_bounce.limit', section: 'Mail sending', label: 'Temporary failures before an address is blocked', help: 'Counted.', type: 'int', value: 3, options: [], min: 2, max: 10, unit: 'failures' },
];

const transports: TransportOption[] = [
    { value: 'cloudron_smtp', label: 'Cloudron SMTP', configured: true, system_problem: null, newsletter_problem: null },
    { value: 'mailgun_api', label: 'Mailgun API', configured: true, system_problem: null, newsletter_problem: null },
    { value: 'custom_smtp', label: 'Custom SMTP', configured: false, system_problem: 'Custom SMTP needs a host and password under Integrations.', newsletter_problem: 'Custom SMTP needs a host and password under Integrations.' },
];

const props = {
    settings,
    transports,
    effectiveNewsletterTransport: 'mailgun_api' as const,
    cloudron: { detected: true, mailer: 'smtp', from: 'newsroom.app@apesnews.org.uk' },
    features: [{ feature: 'Bounce handling', cloudron_smtp: 'No.', mailgun_api: 'Yes.', custom_smtp: 'Depends.' }],
    lastTests: { system: null, newsletters: null },
    testRecipient: 'admin@apes.org.uk',
};

beforeEach(() => {
    setMockPage({
        appName: 'APES Newsroom',
        auth: { user: { id: 1, name: 'Admin', email: 'admin@apes.org.uk', role: 'admin' }, can: { accessStaff: true, accessAdmin: true, accessSuperAdmin: false } },
    });
});

afterEach(() => vi.restoreAllMocks());

describe('Mail sending page', () => {
    it('shows admins the current setup read-only with test panels', () => {
        render(<MailSendingIndex {...props} canManage={false} />);

        expect(screen.getByText(/Only super admins can change these/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save mail sending' })).toBeNull();
        expect(screen.getAllByRole('button', { name: 'Check connection' })).toHaveLength(2);
        expect(screen.getByRole('table', { name: 'Features available on each mail transport' })).toBeInTheDocument();
        expect(screen.getByText('Custom SMTP needs a host and password under Integrations.')).toBeInTheDocument();
    });

    it('lets super admins save the sending method', async () => {
        const user = userEvent.setup();
        render(<MailSendingIndex {...props} canManage />);

        await user.click(screen.getByRole('radio', { name: 'Custom SMTP' }));
        await user.click(screen.getByRole('button', { name: 'Save mail sending' }));

        expect(getInertiaMock().put).toHaveBeenCalledWith('/admin/settings/mail-sending', expect.anything(), expect.anything());
        expect(getInertiaMock().visits[0].data).toEqual({ settings: { mail: { system_transport: 'custom_smtp', soft_bounce: { limit: 3 } } } });
    });

    it('runs a connection check and shows the result', async () => {
        const user = userEvent.setup();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    ok: false,
                    purpose: 'system',
                    transport: 'cloudron_smtp',
                    transport_label: 'Cloudron SMTP',
                    mode: 'connection',
                    message: 'Cloudron SMTP refused the connection or sign-in.',
                    recipients: [],
                    message_id: null,
                    error: 'Connection refused',
                    tested_at: '2026-10-29T12:00:00+00:00',
                }),
                { status: 200 },
            ),
        );
        render(<MailSendingIndex {...props} canManage={false} />);

        await user.click(screen.getAllByRole('button', { name: 'Check connection' })[0]);

        expect(await screen.findByText('Connection refused')).toBeInTheDocument();
        expect(isTransportTestResult({})).toBe(false);
    });
});
