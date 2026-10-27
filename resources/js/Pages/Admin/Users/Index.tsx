import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import FilterBar from '../../../Components/Admin/FilterBar';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';
import { methodLabel, roleLabel, type Role, type UserRow } from './types';

export type UserFilters = { q: string; role: string; method: string; status: string; security: string };

export default function Index({ filters, users, roles }: { filters: UserFilters; users: PageMeta & { data: UserRow[]; total: number }; roles: Role[] }) {
    const [values, setValues] = useState<UserFilters>(filters);
    const set = (key: keyof UserFilters) => (value: string) => setValues((current) => ({ ...current, [key]: value }));

    return (
        <WorkspaceLayout area="Admin" active="users" title="Users" subtitle="Find accounts and review their security">
            <Head title="Users" />
            <p className="text-sm text-muted">
                Every account, with sign-in, security and contact details. For membership plans and reading activity of public readers, see{' '}
                <Link href="/admin/members" className="text-body underline">
                    Members
                </Link>
                .
            </p>
            <FilterBar action="/admin/users" values={values} label="Filter users">
                <label className="text-sm font-semibold text-body">
                    Search
                    <input className="form-input mt-1.5" value={values.q} onChange={(e) => set('q')(e.target.value)} placeholder="Name or email" />
                </label>
                <label className="text-sm font-semibold text-body">
                    Role
                    <select className="form-input mt-1.5" value={values.role} onChange={(e) => set('role')(e.target.value)}>
                        <option value="">Any</option>
                        {roles.map((role) => (
                            <option key={role} value={role}>
                                {roleLabel(role)}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-sm font-semibold text-body">
                    Sign-in method
                    <select className="form-input mt-1.5" value={values.method} onChange={(e) => set('method')(e.target.value)}>
                        <option value="">Any</option>
                        <option value="sso">Cloudron SSO</option>
                        <option value="password">Password</option>
                        <option value="passwordless">Magic link only</option>
                    </select>
                </label>
                <label className="text-sm font-semibold text-body">
                    Status
                    <select className="form-input mt-1.5" value={values.status} onChange={(e) => set('status')(e.target.value)}>
                        <option value="">Any</option>
                        <option value="verified">Email verified</option>
                        <option value="unverified">Email not verified</option>
                        <option value="locked">Locked (suspended)</option>
                    </select>
                </label>
                <label className="text-sm font-semibold text-body">
                    Second factor
                    <select className="form-input mt-1.5" value={values.security} onChange={(e) => set('security')(e.target.value)}>
                        <option value="">Any</option>
                        <option value="two_factor">Authenticator app</option>
                        <option value="passkey">Passkey</option>
                        <option value="none">None</option>
                    </select>
                </label>
            </FilterBar>

            <p className="text-sm text-muted">
                {users.total} user{users.total === 1 ? '' : 's'}
            </p>

            {users.data.length === 0 ? (
                <EmptyState title="No users match these filters." description="Clear the filters to see every account." />
            ) : (
                <DataTable
                    label="Users"
                    rows={users.data}
                    rowKey={(user) => user.id}
                    columns={[
                        {
                            key: 'name',
                            header: 'Name',
                            primary: true,
                            cell: (user) => (
                                <>
                                    <Link href={`/admin/users/${user.id}`} className="font-semibold text-body hover:text-teal-deep hover:underline">
                                        {user.name}
                                    </Link>
                                    <p className="text-xs text-muted">{user.email}</p>
                                    <Link
                                        href={`/staff/people/users/${user.id}`}
                                        className="text-xs font-medium text-teal-deep hover:underline"
                                        aria-label={`Person page for ${user.name}`}
                                    >
                                        Person page
                                    </Link>
                                    {user.locked && (
                                        <Badge tone="error" className="mt-1">
                                            Locked
                                        </Badge>
                                    )}
                                </>
                            ),
                        },
                        { key: 'role', header: 'Role', cell: (user) => roleLabel(user.role) },
                        { key: 'method', header: 'Sign-in', cell: (user) => methodLabel(user.sign_in_method) },
                        {
                            key: 'security',
                            header: 'Second factor',
                            cell: (user) =>
                                [user.two_factor_enabled ? 'App' : null, user.passkeys_count > 0 ? `${user.passkeys_count} passkey${user.passkeys_count === 1 ? '' : 's'}` : null]
                                    .filter(Boolean)
                                    .join(', ') || 'None',
                        },
                        {
                            key: 'email',
                            header: 'Email',
                            cell: (user) => (user.email_verified ? <Badge tone="ok">Verified</Badge> : <Badge tone="warning">Not verified</Badge>),
                        },
                    ]}
                />
            )}

            <Pagination page={users} label="Users" />
        </WorkspaceLayout>
    );
}
