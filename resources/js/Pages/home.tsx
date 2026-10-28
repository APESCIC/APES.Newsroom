import { Head } from '@inertiajs/react';
import ApesLogo from '../Components/Brand/ApesLogo';
import ChannelSection, { type HomeChannel } from '../Components/Home/ChannelSection';
import DeskPanel, { type PostCard } from '../Components/Home/DeskPanel';
import RecentStoryCard from '../Components/Home/RecentStoryCard';
import PublicLayout from '../Components/Layout/PublicLayout';
import ResponsiveHeroImage from '../Components/Media/ResponsiveHeroImage';

function FeaturedHeroImage({ featured }: { featured: PostCard }) {
    if (!featured.hero_image) {
        return (
            <aside className="sg-card mx-auto w-full max-w-sm p-8">
                <ApesLogo
                    variant="square"
                    alt="Association for the Protection of Exotic Species"
                    className="h-auto w-full object-contain"
                />
                <div className="mt-8 border-t border-border pt-6 text-center">
                    <h2 className="text-sm font-bold text-sg-ink">Our mission</h2>
                    <p className="mt-2 text-xs leading-6 text-muted">
                        Securing a sustainable future for wildlife and communities through science, rescue, and compassionate care.
                    </p>
                </div>
            </aside>
        );
    }

    return (
        <figure className="overflow-hidden rounded-sg-card border border-white/85 shadow-sg-card">
            <ResponsiveHeroImage
                image={featured}
                alt={featured.hero_image_alt ?? featured.title}
                sizes="(min-width: 48rem) 36rem, 100vw"
                className="aspect-[4/5] w-full object-cover sm:aspect-[3/4]"
                priority
            />
        </figure>
    );
}

function MissionFallback() {
    return (
        <aside className="sg-card mx-auto w-full max-w-sm p-8">
            <ApesLogo
                variant="square"
                alt="Association for the Protection of Exotic Species"
                className="h-auto w-full object-contain"
            />
            <div className="mt-8 border-t border-border pt-6 text-center">
                <h2 className="text-sm font-bold text-sg-ink">Our mission</h2>
                <p className="mt-2 text-xs leading-6 text-muted">
                    Securing a sustainable future for wildlife and communities through science, rescue, and compassionate care.
                </p>
            </div>
        </aside>
    );
}

export default function Home({
    featured,
    recent,
    channels,
}: {
    featured?: PostCard;
    recent: PostCard[];
    channels: HomeChannel[];
}) {
    return (
        <PublicLayout>
            <Head title="Home" />
            <main id="main-content">
                <section className="px-5 py-12 sm:px-6 md:py-16">
                    <div className="sg-card mx-auto max-w-public p-8 md:p-12">
                        <div className="grid items-center gap-12 md:grid-cols-2">
                            <DeskPanel featured={featured} />
                            {featured ? <FeaturedHeroImage featured={featured} /> : <MissionFallback />}
                        </div>
                    </div>
                </section>

                {channels.length > 0 && (
                    <section className="px-5 py-12 sm:px-6 sm:py-16" aria-label="APES newsroom channels">
                        <div className="mx-auto max-w-public">
                            <h2 className="sr-only">Newsroom channels</h2>
                            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                                {channels.map((channel) => (
                                    <ChannelSection key={channel.slug} channel={channel} />
                                ))}
                            </div>
                        </div>
                    </section>
                )}

                <section className="px-5 py-12 sm:px-6" aria-labelledby="recent-heading">
                    <div className="mx-auto max-w-public">
                        <h2 id="recent-heading" className="text-2xl font-bold text-sg-ink">Recent stories</h2>
                        {recent.length > 0 ? (
                            <div className="mt-8 grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                                {recent.map((post) => (
                                    <RecentStoryCard key={post.slug} post={post} />
                                ))}
                            </div>
                        ) : (
                            <p className="sg-card mt-6 p-6 text-muted">
                                No additional stories are published yet.
                            </p>
                        )}
                    </div>
                </section>
            </main>
        </PublicLayout>
    );
}
