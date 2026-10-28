import { useForm } from '@inertiajs/react';
import { Eraser, Pencil, UserRound } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import type { ProfileInfo } from '../../Pages/Staff/People/types';
import ReasonActionButton from '../Staff/ReasonActionButton';
import Badge from '../Workspace/ui/Badge';
import FormField from '../Workspace/ui/FormField';
import GlassCard from '../Workspace/ui/GlassCard';
import PillButton from '../Workspace/ui/PillButton';

type ProfileForm = {
    display_name: string;
    bio: string;
    avatar: File | null;
    remove_avatar: boolean;
    reason: string;
};

/** Public profile fields with staff edit and reset (#572). Moderation stays on the admin queue. */
export default function ProfileCard({ userId, profile, canEdit }: { userId: number; profile: ProfileInfo; canEdit: boolean }) {
    const [editing, setEditing] = useState(false);
    const form = useForm<ProfileForm>({
        display_name: profile.display_name ?? '',
        bio: profile.bio ?? '',
        avatar: null,
        remove_avatar: false,
        reason: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/staff/people/users/${userId}/profile`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.setData((current) => ({ ...current, avatar: null, remove_avatar: false, reason: '' }));
                setEditing(false);
            },
        });
    }

    return (
        <GlassCard
            as="section"
            aria-label="Public profile"
            title="Public profile"
            description={profile.public_opt_in ? 'They have chosen to show this profile publicly once approved.' : 'Private: they have not opted in to a public profile.'}
            actions={
                <>
                    <Badge tone="info">Moderation: {profile.moderation_status}</Badge>
                    {canEdit && !editing && (
                        <PillButton size="sm" variant="secondary" icon={Pencil} onClick={() => setEditing(true)}>
                            Edit
                        </PillButton>
                    )}
                    {canEdit && (
                        <ReasonActionButton
                            label="Reset fields"
                            icon={Eraser}
                            title="Reset the public profile?"
                            description="Clears the display name, bio and avatar. The moderation status does not change."
                            confirmLabel="Reset fields"
                            destructive
                            url={`/staff/people/users/${userId}/profile/reset`}
                        />
                    )}
                </>
            }
        >
            <div className="flex items-start gap-4">
                {profile.avatar_url ? (
                    <img src={profile.avatar_url} alt="" className="h-16 w-16 shrink-0 rounded-full object-cover" />
                ) : (
                    <span className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-brand-mist text-teal-deep">
                        <UserRound aria-hidden="true" className="h-6 w-6" />
                    </span>
                )}
                <div className="min-w-0 text-sm">
                    <p className="font-semibold text-body">{profile.display_name ?? 'No display name'}</p>
                    <p className="mt-1 text-muted">{profile.bio ?? 'No bio.'}</p>
                </div>
            </div>

            {canEdit && editing && (
                <form className="mt-5 flex flex-col gap-4 border-t border-border pt-5" onSubmit={submit} aria-label="Edit public profile">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Display name" error={form.errors.display_name}>
                            <input className="form-input" maxLength={80} value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} />
                        </FormField>
                        <FormField label="Avatar" hint="JPEG, PNG or WebP, up to 2 MB." error={form.errors.avatar}>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                className="form-input"
                                onChange={(e) => form.setData('avatar', e.target.files?.[0] ?? null)}
                            />
                        </FormField>
                    </div>
                    <FormField label="Bio" error={form.errors.bio}>
                        <textarea className="form-input" rows={3} maxLength={500} value={form.data.bio} onChange={(e) => form.setData('bio', e.target.value)} />
                    </FormField>
                    {profile.avatar_url && (
                        <label className="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" checked={form.data.remove_avatar} onChange={(e) => form.setData('remove_avatar', e.target.checked)} />
                            Remove the current avatar
                        </label>
                    )}
                    <FormField label="Reason (recorded in the audit log)" error={form.errors.reason}>
                        <input className="form-input" maxLength={500} required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </FormField>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" disabled={form.processing} className="sg-btn sg-btn-primary">
                            Save public profile
                        </button>
                        <PillButton
                            variant="ghost"
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setEditing(false);
                            }}
                        >
                            Cancel
                        </PillButton>
                    </div>
                </form>
            )}
        </GlassCard>
    );
}
