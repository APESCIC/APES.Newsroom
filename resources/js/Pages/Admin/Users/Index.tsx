import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import FilterBar from '../../../Components/Admin/FilterBar';
import Pagination from '../../../Components/Admin/Pagination';
import type { Paginator } from '../../../Components/Admin/types';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import { methodLabel, roleLabel, type Role, type UserRow } from './types';

export type UserFilters = { q: string; role: string; method: string; status: string; security: string };

export default function Index({ filters, users, roles }: { filters: UserFilters; users: Paginator<UserRow>; roles: Role[] }) {
    const [values, setValues] = useState<UserFilters>(filters);
    const set = (key: keyof UserFilters) => (value: string) => setValues((current) => ({ ...current, [key]: value }));

    return (
        <WorkspaceLayout area="Admin" active="users" title="Users" subtitle="Find accounts and review their security">
            <Head title="Users" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6">
                <FilterBar action="/admin/users" values={values} label="Filter users">
                    <label className="text-sm font-bold text-body">
                        Search
                        <input className="form-input mt-1" value={values.q} onChange={(e) => set('q')(e.target.value)} placeholder="Name or email" />
                    </label>
                    <label className="text-sm font-bold text-body">
                        Role
                        <select className="form-input mt-1" value={values.role} onChange={(e) => set('role')(e.target.value)}>
                            <option value="">Any</option>
                            {roles.map((role) => (
                                <option key={role} value={role}>
                                    {roleLabel(role)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm font-bold text-body">
                        Sign-in method
                        <select className="form-input mt-1" value={values.method} onChange={(e) => set('method')(e.target.value)}>
                            <option value="">Any</option>
                            <option value="sso">Cloudron SSO</option>
                            <option value="password">Password</option>
                            <option value="passwordless">Magic link only</option>
                        </select>
                    </label>
                    <label className="text-sm font-bold text-body">
                        Status
                        <select className="form-input mt-1" value={values.status} onChange={(e) => set('status')(e.target.value)}>
                            <option value="">Any</option>
                            <option value="verified">Email verified</option>
                            <option value="unverified">Email not verified</option>
                            <option value="locked">Locked</option>
                        </select>
                    </label>
                    <label className="text-sm font-bold text-body">
                        Second factor
                        <select className="form-input mt-1" value={values.security} onChange={(e) => set('security')(e.target.value)}>
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
                    <p className="workspace-glass-card p-8 text-center text-muted">No users match these filters.</p>
                ) : (
                    <div className="workspace-glass-card overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted">
                                <tr>
                                    <th scope="col" className="px-4 py-3">Name</th>
                                    <th scope="col" className="px-4 py-3">Role</th>
                                    <th scope="col" className="px-4 py-3">Sign-in</th>
                                    <th scope="col" className="px-4 py-3">Second factor</th>
                                    <th scope="col" className="px-4 py-3">Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.data.map((user) => (
                                    <tr key={user.id} className="border-t border-border">
                                        <td className="px-4 py-3">
                                            <Link href={`/admin/users/${user.id}`} className="font-semibold text-body underline">
                                                {user.name}
                                            </Link>
                                            <p className="text-xs text-muted">{user.email}</p>
                                            {user.locked && <p className="text-xs font-bold text-danger">Locked</p>}
                                        </td>
                                        <td className="px-4 py-3 text-body">{roleLabel(user.role)}</td>
                                        <td className="px-4 py-3 text-body">{methodLabel(user.sign_in_method)}</td>
                                        <td className="px-4 py-3 text-body">
                                            {[user.two_factor_enabled ? 'App' : null, user.passkeys_count > 0 ? `${user.passkeys_count} passkey${user.passkeys_count === 1 ? '' : 's'}` : null]
                                                .filter(Boolean)
                                                .join(', ') || 'None'}
                                        </td>
                                        <td className="px-4 py-3 text-body">{user.email_verified ? 'Verified' : 'Not verified'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination page={users} label="Users" />
            </div>
        </WorkspaceLayout>
    );
}
