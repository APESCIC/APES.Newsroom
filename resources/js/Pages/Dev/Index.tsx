import { Head, router, usePage } from '@inertiajs/react';
import AuthCard from '../../Components/Auth/AuthCard';

type Profile = { key: string; label: string; email: string };

type PageProps = {
    profiles: Profile[];
    current: string | null;
    returnTo: string | null;
    flash?: { status?: string | null };
};

export default function DevIndex({ profiles, current, returnTo }: PageProps) {
    const { flash } = usePage<PageProps>().props;

    const signIn = (key: string) => {
        router.post('/_dev/login', {
            profile: key,
            return_to: returnTo ?? undefined,
        });
    };

    return (
        <>
            <Head title="Developer sign-in" />
            <AuthCard title="Developer sign-in">
                <p className="mb-4 text-sm text-muted">
                    Local only. Signs in as one of the three demo accounts.
                </p>
                {flash?.status && (
                    <p className="mb-4 rounded-sg-field border border-warning/40 bg-warning-mist px-3 py-2 text-sm text-warning">
                        {flash.status}
                    </p>
                )}
                <div className="flex flex-col gap-2">
                    {profiles.map((profile) => (
                        <button
                            key={profile.key}
                            type="button"
                            onClick={() => signIn(profile.key)}
                            className={
                                current === profile.key
                                    ? 'button-primary w-full'
                                    : 'button-secondary w-full'
                            }
                        >
                            {profile.label}
                            <span className="ml-2 text-xs opacity-70">{profile.email}</span>
                        </button>
                    ))}
                </div>
            </AuthCard>
        </>
    );
}
