import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import { formatWhen, methodLabel, resultLabel, roleLabel, type UserDetail } from './types';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="glass-form-panel flex flex-col gap-3" aria-label={title}>
            <h2 className="text-lg font-bold text-body">{title}</h2>
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
                <Link href="/admin/users" className="button-secondary">
                    All users
                </Link>
            }
        >
            <Head title={`${user.name} · Users`} />
            <div className="mx-auto flex max-w-4xl flex-col gap-6">
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
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="text-muted">
                                    <tr>
                                        <th scope="col" className="py-2 pr-4">When</th>
                                        <th scope="col" className="py-2 pr-4">Result</th>
                                        <th scope="col" className="py-2 pr-4">Event</th>
                                        <th scope="col" className="py-2 pr-4">Method</th>
                                        <th scope="col" className="py-2 pr-4">IP address</th>
                                        <th scope="col" className="py-2">Device</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {user.login_history.map((event) => (
                                        <tr key={event.id} className="border-t border-border">
                                            <td className="py-2 pr-4 text-body">{formatWhen(event.created_at)}</td>
                                            <td className={`py-2 pr-4 font-semibold ${event.result === 'failure' ? 'text-danger' : 'text-body'}`}>{resultLabel(event.result)}</td>
                                            <td className="py-2 pr-4 font-mono text-body">{event.action.replace(/^auth\./, '')}</td>
                                            <td className="py-2 pr-4 text-body">{event.method ?? '—'}</td>
                                            <td className="py-2 pr-4 text-body">{event.ip_address ?? '—'}</td>
                                            <td className="py-2 text-body">{[event.browser, event.platform].filter(Boolean).join(' on ') || '—'}</td>
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
                                    <span className="text-body">{token.name}</span>
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
                                    <span className="font-mono text-body">{event.action}</span>
                                    <span className="text-muted">
                                        {event.actor ? event.actor.name : 'System'} · {formatWhen(event.created_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link href={`/admin/audit-log?actor=${user.id}`} className="text-sm text-body underline">
                        Everything this user did in the audit log
                    </Link>
                </Section>
            </div>
        </WorkspaceLayout>
    );
}
