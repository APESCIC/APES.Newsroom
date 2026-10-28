import { Head } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

type Profile = {
    display_name: string;
    bio: string | null;
    avatar_url: string | null;
};

export default function ProfileShow({ profile }: { profile: Profile }) {
    return (
        <PublicLayout>
            <Head title={profile.display_name} />
            <main id="main-content" className="mx-auto max-w-lg px-5 py-12 sm:px-6">
                <div className="form-panel sm:p-8">
                    {profile.avatar_url && (
                        <img
                            src={profile.avatar_url}
                            alt=""
                            className="h-24 w-24 rounded-full border-2 border-white object-cover shadow-sg-card"
                        />
                    )}
                    <h1 className="mt-4 text-2xl font-bold text-body">{profile.display_name}</h1>
                    {profile.bio && <p className="mt-3 text-muted">{profile.bio}</p>}
                </div>
            </main>
        </PublicLayout>
    );
}
