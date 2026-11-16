import { ShieldCheck } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

export type RoleDescription = {
    key: string;
    label: string;
    description: string;
};

export type AbilityRow = {
    group: string;
    action: string;
    public: boolean;
    staff: boolean;
    admin: boolean;
    note: string | null;
};

function Allowed({ yes }: { yes: boolean }) {
    return (
        <span className={yes ? 'font-semibold text-teal-deep' : 'text-muted'}>
            {yes ? 'Yes' : 'No'}
        </span>
    );
}

export default function Index({
    roles,
    abilities,
}: {
    roles: RoleDescription[];
    abilities: AbilityRow[];
}) {
    return (
        <WorkspaceLayout
            area="Admin"
            active="permissions"
            title="Roles and permissions"
            subtitle="Read-only view of who may do what. Roles come from Cloudron groups; nothing here is editable."
        >
            <section aria-labelledby="role-descriptions-heading" className="mb-8">
                <h2 id="role-descriptions-heading" className="text-lg font-semibold text-body">
                    Role descriptions
                </h2>
                <p className="mt-1 text-sm text-muted">
                    Public, Staff and Admin are the only live Newsroom roles. Super admin was merged into Admin in
                    v1.28.0.
                </p>
                <ul className="mt-4 grid gap-4 lg:grid-cols-3">
                    {roles.map((role) => (
                        <li key={role.key} className="border-t border-border pt-3">
                            <p className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-teal-deep">
                                <ShieldCheck aria-hidden="true" className="h-4 w-4" />
                                {role.label}
                            </p>
                            <p className="mt-2 text-sm text-body">{role.description}</p>
                        </li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby="permission-matrix-heading">
                <h2 id="permission-matrix-heading" className="text-lg font-semibold text-body">
                    Permission matrix
                </h2>
                <p className="mt-1 text-sm text-muted">
                    High-level abilities that match the policies and gates in code. For the full route matrix see the
                    automated tests.
                </p>
                <div className="mt-4 overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <caption className="sr-only">Which roles may perform each action</caption>
                        <thead>
                            <tr className="border-b border-border text-muted">
                                <th scope="col" className="py-2 pr-4 font-semibold">
                                    Group
                                </th>
                                <th scope="col" className="py-2 pr-4 font-semibold">
                                    Action
                                </th>
                                <th scope="col" className="py-2 pr-4 font-semibold">
                                    Public
                                </th>
                                <th scope="col" className="py-2 pr-4 font-semibold">
                                    Staff
                                </th>
                                <th scope="col" className="py-2 pr-4 font-semibold">
                                    Admin
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {abilities.map((row) => (
                                <tr key={`${row.group}-${row.action}`} className="border-b border-border/70 align-top">
                                    <th scope="row" className="py-3 pr-4 font-medium text-body">
                                        {row.group}
                                    </th>
                                    <td className="py-3 pr-4 text-body">
                                        <p>{row.action}</p>
                                        {row.note ? <p className="mt-1 text-xs text-muted">{row.note}</p> : null}
                                    </td>
                                    <td className="py-3 pr-4">
                                        <Allowed yes={row.public} />
                                    </td>
                                    <td className="py-3 pr-4">
                                        <Allowed yes={row.staff} />
                                    </td>
                                    <td className="py-3 pr-4">
                                        <Allowed yes={row.admin} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </WorkspaceLayout>
    );
}
