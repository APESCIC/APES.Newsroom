import { Contact, ShieldCheck, Users } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import AccountCard from '../../../Components/People/AccountCard';
import ContactCard from '../../../Components/People/ContactCard';
import MembershipCard from '../../../Components/People/MembershipCard';
import ProfileCard from '../../../Components/People/ProfileCard';
import SubscriptionsCard from '../../../Components/People/SubscriptionsCard';
import GlassCard from '../../../Components/Workspace/ui/GlassCard';
import PillButton from '../../../Components/Workspace/ui/PillButton';
import type { Person } from './types';

/** One page per person: account, membership, newsletters and public profile (#568). */
export default function Show({ person }: { person: Person }) {
    if (person.kind === 'contact') {
        return (
            <WorkspaceLayout
                area="Staff"
                active="subscribers"
                title={person.contact.name ?? person.contact.email}
                subtitle={person.contact.email}
                actions={
                    <PillButton href="/staff/subscribers" variant="secondary" icon={Users}>
                        All subscribers
                    </PillButton>
                }
            >
                <ContactCard person={person} />
                <SubscriptionsCard mailing={person.mailing} canManage={person.can.manage_subscriptions} canSuppress={person.can.suppress} />
            </WorkspaceLayout>
        );
    }

    const { user, can } = person;

    return (
        <WorkspaceLayout
            area="Staff"
            active="people"
            title={user.name}
            subtitle={user.email}
            actions={
                <>
                    <PillButton href="/staff/people" variant="secondary" icon={Contact}>
                        All people
                    </PillButton>
                    {can.view_admin_user && (
                        <PillButton href={`/admin/users/${user.id}`} variant="secondary" icon={ShieldCheck}>
                            Security and roles
                        </PillButton>
                    )}
                </>
            }
        >
            <AccountCard person={person} />
            <MembershipCard person={person} />
            {person.mailing ? (
                <SubscriptionsCard mailing={person.mailing} canManage={can.manage_subscriptions} canSuppress={can.suppress} />
            ) : (
                <GlassCard as="section" aria-label="Newsletters" title="Newsletters">
                    <p className="text-sm text-muted">No mailing contact is linked to this account yet. It is linked once they verify their email address.</p>
                </GlassCard>
            )}
            {person.profile ? (
                <ProfileCard userId={user.id} profile={person.profile} canEdit={can.update_profile} />
            ) : (
                <GlassCard as="section" aria-label="Public profile" title="Public profile">
                    <p className="text-sm text-muted">They have not set up a public profile.</p>
                </GlassCard>
            )}
        </WorkspaceLayout>
    );
}
