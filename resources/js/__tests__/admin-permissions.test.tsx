import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import PermissionsIndex, { type AbilityRow, type RoleDescription } from '../Pages/Admin/Permissions/Index';
import type { SharedPageProps } from '../types/page';
import { setMockPage } from '../test/inertia';

const adminProps: SharedPageProps = {
    appName: 'APES Newsroom',
    auth: {
        user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
        can: { accessStaff: true, accessAdmin: true },
    },
};

const roles: RoleDescription[] = [
    { key: 'public', label: 'Public', description: 'Public readers manage their own account.' },
    { key: 'staff', label: 'Staff', description: 'Staff write and send newsletters.' },
    { key: 'admin', label: 'Admin', description: 'Admins manage users and settings.' },
];

const abilities: AbilityRow[] = [
    {
        group: 'Admin panel',
        action: 'Open Admin workspace',
        public: false,
        staff: false,
        admin: true,
        note: null,
    },
];

describe('Admin permission matrix page', () => {
    beforeEach(() => {
        setMockPage(adminProps);
    });

    it('shows Public, Staff and Admin descriptions', () => {
        render(<PermissionsIndex roles={roles} abilities={abilities} />);

        expect(screen.getByRole('heading', { name: 'Role descriptions' })).toBeInTheDocument();
        expect(screen.getByText('Public readers manage their own account.')).toBeInTheDocument();
        expect(screen.getByText('Staff write and send newsletters.')).toBeInTheDocument();
        expect(screen.getByText('Admins manage users and settings.')).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Public' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Staff' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Admin' })).toBeInTheDocument();
    });

    it('marks Roles and permissions as the current nav item', () => {
        render(<PermissionsIndex roles={roles} abilities={abilities} />);

        const nav = screen.getByRole('navigation', { name: 'Admin workspace' });
        expect(within(nav).getByRole('link', { name: 'Roles and permissions' })).toHaveAttribute(
            'href',
            '/admin/permissions',
        );
        expect(within(nav).getByRole('link', { name: 'Roles and permissions' })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });
});
