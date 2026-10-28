import { Link } from '@inertiajs/react';
import { Contact, Pencil, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import ContactDetailsSummary from '../../../Components/Account/ContactDetailsSummary';
import UserEraseControl from '../../../Components/Admin/UserEraseControl';
import UserRoleControl from '../../../Components/Admin/UserRoleControl';
import UserSecurityActions from '../../../Components/Admin/UserSecurityActions';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import PillButton from '../../../Components/Workspace/ui/PillButton';
import { eventLabel, formatWhen, methodLabel, resultLabel, roleLabel, type UserDetail } from './types';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-label={title}>
            <h2 className="text-base font-semibold text-body">{title}</h2>
            {children}
        </section>
    );
}

function Empty({ children }: { children: string }) {
    return <p className="text-sm text-muted">{children}</p>;
}

export default function Show({ user }: { user: UserDetail }) {
    return (
        <WorkspaceLayout
            area="Admin"
            active="users"
            title={user.name}
            subtitle={user.email}
            actions={
                <>
                    <PillButton href="/admin/users" variant="secondary" icon={Users}>
                        All users
                    </PillButton>
                    <PillButton href={`/staff/people/users/${user.id}`} variant="secondary" icon={Contact}>
                        Person page
                    </PillButton>
                    {user.can.update && (
                        <PillButton href={`/admin/users/${user.id}/edit`} icon={Pencil}>
                            Edit details
                        </PillButton>
                    )}
                </>
            }
        >
            <>
                <Section title="Account access">
                    {user.lock ? (
                        <p className="text-sm font-semibold text-danger">
                            Locked {formatWhen(user.lock.locked_at)}
                            {user.lock.by ? ` by ${user.lock.by.name}` : ''}
                            {user.lock.reason ? ` · ${user.lock.reason}` : ''}
                        </p>
                    ) : (
                        <p className="text-sm text-body">Active. The account can sign in.</p>
                    )}
                    <UserSecurityActions user={user} />
                </Section>

                <Section title="Account">
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted">Role</dt>
                            <dd className="text-body">{roleLabel(user.role)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted">Role source</dt>
                            <dd className="text-body">
                                {user.role_source.type === 'directory'
                                    ? `Directory groups: ${user.role_source.groups.join(', ') || 'none recorded'}`
                                    : 'Set in the Newsroom'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted">Sign-in method</dt>
                            <dd className="text-body">{methodLabel(user.sign_in_method)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted">Email</dt>
                            <dd className="text-body">{user.email_verified ? `Verified ${user.email_verified_at ? formatWhen(user.email_verified_at) : ''}` : 'Not verified'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted">Joined</dt>
                            <dd className="text-body">{formatWhen(user.created_at)}</dd>
                        </div>
                    </dl>
                    {user.pending_email && (
                        <p className="text-sm text-body" role="status">
                            Waiting for confirmation of a change to <strong>{user.pending_email.email}</strong>
                            {user.pending_email.expires_at ? ` (link expires ${formatWhen(user.pending_email.expires_at)})` : ''}.
                        </p>
                    )}
                    <UserRoleControl user={user} />
                </Section>

                <Section title="Contact details">
                    <p className="text-sm text-muted">Private. Visible only to the account holder and administrators.</p>
                    <ContactDetailsSummary details={user.contact_details} />
                </Section>

                <Section title="Second factor">
                    {user.sso_managed ? (
                        <Empty>Managed by Cloudron SSO, which enforces multi-factor sign-in.</Empty>
                    ) : (
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted">Authenticator app</dt>
                                <dd className="text-body">
                                    {user.two_factor_enabled ? `On (${user.recovery_codes_remaining} recovery codes left)` : 'Off'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted">Passkeys</dt>
                                <dd className="text-body">
                                    {user.passkeys.length === 0 ? 'None' : (
                                        <ul>
                                            {user.passkeys.map((passkey) => (
                                                <li key={passkey.id}>
                                                    {passkey.name} · last used {formatWhen(passkey.last_used_at)}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </dd>
                            </div>
                        </dl>
                    )}
                </Section>

                <Section title="Active sessions">
                    {user.sessions.length === 0 ? (
                        <Empty>No active sessions.</Empty>
                    ) : (
                        <ul className="flex flex-col gap-2 text-sm">
                            {user.sessions.map((session) => (
                                <li key={session.id} className="flex flex-wrap justify-between gap-2">
                                    <span className="text-body">
                                        {[session.browser, session.platform].filter(Boolean).join(' on ') || 'Unknown device'}
                                        {session.remembered ? ' · remembered' : ''}
                                    </span>
                                    <span className="text-muted">
                                        {session.ip_address ?? '—'} · {formatWhen(session.last_seen_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title="Sign-in history">
                    {user.login_history.length === 0 ? (
                        <Empty>No sign-in activity recorded.</Empty>
                    ) : (
                        <div className="-mx-5 sm:-mx-6">
                            <table className="sg-table text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col">When</th>
                                        <th scope="col">Result</th>
                                        <th scope="col">Event</th>
                                        <th scope="col">Method</th>
                                        <th scope="col">IP address</th>
                                        <th scope="col">Device</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {user.login_history.map((event) => (
                                        <tr key={event.id}>
                                            <td data-label="When" className="text-body">{formatWhen(event.created_at)}</td>
                                            <td data-label="Result">
                                                <Badge tone={event.result === 'failure' ? 'error' : 'ok'}>{resultLabel(event.result)}</Badge>
                                            </td>
                                            <td data-label="Event" className="font-mono text-body">{event.action.replace(/^auth\./, '')}</td>
                                            <td data-label="Method" className="text-body">{event.method ?? '—'}</td>
                                            <td data-label="IP address" className="text-body">{event.ip_address ?? '—'}</td>
                                            <td data-label="Device" className="text-body">{[event.browser, event.platform].filter(Boolean).join(' on ') || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>

                <Section title="Admin API tokens">
                    {user.api_tokens.length === 0 ? (
                        <Empty>No tokens.</Empty>
                    ) : (
                        <ul className="flex flex-col gap-2 text-sm">
                            {user.api_tokens.map((token) => (
                                <li key={token.id} className="flex flex-wrap justify-between gap-2">
                                    <span className="text-body">
                                        {token.name}
                                        {token.status && token.status !== 'active' ? ` (${token.status})` : ''}
                                    </span>
                                    <span className="text-muted">
                                        created {formatWhen(token.created_at)} · last used {formatWhen(token.last_used_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title="Recent security events">
                    {user.security_events.length === 0 ? (
                        <Empty>No account changes recorded.</Empty>
                    ) : (
                        <ul className="flex flex-col gap-2 text-sm">
                            {user.security_events.map((event) => (
                                <li key={event.id} className="flex flex-wrap justify-between gap-2">
                                    <span className="text-body">
                                        <span className="font-mono">{eventLabel(event.action)}</span>
                                        {event.payload.length > 0 && (
                                            <span className="block text-xs text-muted">
                                                {event.payload.map((pair) => `${pair.key}: ${pair.value}`).join(' · ')}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted">
                                        {event.actor ? event.actor.name : 'System'} · {formatWhen(event.created_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap gap-4">
                        <Link href={`/admin/audit-log?actor=${user.id}`} className="text-sm text-body underline">
                            Everything this user did in the audit log
                        </Link>
                        <Link href={`/admin/audit-log?subject_type=User&subject_id=${user.id}`} className="text-sm text-body underline">
                            Everything done to this account
                        </Link>
                    </div>
                </Section>

                <Section title="Delete user">
                    <UserEraseControl user={user} />
                </Section>
            </>
        </WorkspaceLayout>
    );
}
