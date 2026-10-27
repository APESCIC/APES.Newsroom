import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ReasonConfirmDialog from '../Components/Admin/ReasonConfirmDialog';
import type { Paginator } from '../Components/Admin/types';
import AuditLogIndex, { type AuditEntry, type AuditFilters } from '../Pages/Admin/AuditLog/Index';
import { getInertiaMock, setMockPage } from '../test/inertia';

const emptyFilters: AuditFilters = { actor: '', action: '', subject_type: '', subject_id: '', from: '', to: '', ip: '' };

function page(data: AuditEntry[], overrides: Partial<Paginator<AuditEntry>> = {}): Paginator<AuditEntry> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: data.length,
        prev_page_url: null,
        next_page_url: null,
        ...overrides,
    };
}

const entry: AuditEntry = {
    id: 7,
    created_at: '2026-10-20T10:00:00+00:00',
    action: 'settings.updated',
    actor: { id: 1, name: 'Alex Admin', email: 'alex@example.test' },
    subject: { type: 'Post', id: 4 },
    ip_address: '203.0.113.9',
    user_agent: 'Firefox',
    payload: [{ key: 'changes.editor.driver.to', value: '<script>alert(1)</script>' }],
};

describe('Admin audit log page', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
        });
    });

    it('lists entries with actor, subject and escaped payload values', async () => {
        const user = userEvent.setup();
        render(<AuditLogIndex filters={emptyFilters} entries={page([entry])} subjectTypes={['Post']} />);

        const item = screen.getByTestId('audit-entry');
        expect(within(item).getByText('settings.updated')).toBeInTheDocument();
        expect(within(item).getByText('Alex Admin (alex@example.test)')).toBeInTheDocument();
        expect(within(item).getByText('Post #4')).toBeInTheDocument();

        await user.click(within(item).getByText('Details'));
        expect(within(item).getByText('<script>alert(1)</script>')).toBeInTheDocument();
        expect(document.querySelector('script')).toBeNull();
    });

    it('submits only non-empty filters', async () => {
        const user = userEvent.setup();
        render(<AuditLogIndex filters={emptyFilters} entries={page([])} subjectTypes={['Post']} />);

        await user.type(screen.getByLabelText('Action starts with'), 'auth.');
        await user.selectOptions(screen.getByLabelText('Subject type'), 'Post');
        await user.click(screen.getByRole('button', { name: 'Apply filters' }));

        expect(getInertiaMock().get).toHaveBeenCalledWith('/admin/audit-log', { action: 'auth.', subject_type: 'Post' }, expect.anything());
        expect(screen.getByText('No audit records match these filters.')).toBeInTheDocument();
    });

    it('links the CSV export with the applied filters and marks the nav item current', () => {
        render(<AuditLogIndex filters={{ ...emptyFilters, action: 'auth.' }} entries={page([entry])} subjectTypes={[]} />);

        expect(screen.getByRole('link', { name: 'Export CSV' })).toHaveAttribute('href', '/admin/audit-log/export?action=auth.');
        const nav = screen.getByRole('navigation', { name: 'Admin workspace' });
        expect(within(nav).getByRole('link', { name: 'Audit log' })).toHaveAttribute('aria-current', 'page');
    });

    it('shows pagination when there is more than one page', () => {
        render(
            <AuditLogIndex
                filters={emptyFilters}
                entries={page([entry], { last_page: 2, total: 30, next_page_url: '/admin/audit-log?page=2' })}
                subjectTypes={[]}
            />,
        );

        expect(screen.getByRole('navigation', { name: 'Audit log pagination' })).toHaveTextContent('Page 1 of 2');
        expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute('href', '/admin/audit-log?page=2');
    });
});

describe('ReasonConfirmDialog', () => {
    it('requires a reason before confirming', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        render(
            <ReasonConfirmDialog open title="Lock account" description="They will be signed out." confirmLabel="Lock" onConfirm={onConfirm} onCancel={vi.fn()} />,
        );

        const dialog = screen.getByRole('dialog', { name: 'Lock account' });
        expect(dialog).toHaveAccessibleDescription('They will be signed out.');
        expect(screen.getByRole('button', { name: 'Lock' })).toBeDisabled();
        expect(screen.getByLabelText('Reason (recorded in the audit log)')).toHaveFocus();

        await user.type(screen.getByLabelText('Reason (recorded in the audit log)'), '  Compromised account  ');
        await user.click(screen.getByRole('button', { name: 'Lock' }));

        expect(onConfirm).toHaveBeenCalledWith('Compromised account');
    });

    it('closes on Escape and renders nothing when closed', async () => {
        const user = userEvent.setup();
        const onCancel = vi.fn();
        const { rerender } = render(
            <ReasonConfirmDialog open title="Lock account" description="d" confirmLabel="Lock" onConfirm={vi.fn()} onCancel={onCancel} />,
        );

        await user.keyboard('{Escape}');
        expect(onCancel).toHaveBeenCalled();

        rerender(<ReasonConfirmDialog open={false} title="Lock account" description="d" confirmLabel="Lock" onConfirm={vi.fn()} onCancel={onCancel} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
