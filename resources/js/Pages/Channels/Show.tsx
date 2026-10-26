import { Head, Link } from '@inertiajs/react';
import { channelMeta, channelTintClass } from '../../channelMeta';
import LineIcon from '../../Components/Icons/LineIcon';
import PublicLayout from '../../Components/Layout/PublicLayout';
import ResponsiveHeroImage, { type HeroImageFields } from '../../Components/Media/ResponsiveHeroImage';
import type { PublicChannel } from '../../types/page';

type Post = HeroImageFields & {
    title: string;
    slug: string;
    excerpt: string | null;
    author: string;
    published_at: string | null;
};

type Seo = {
    title: string;
    description: string;
    url: string;
    image: string;
    rss: string;
};

export default function ChannelShow({
    channel,
    posts,
    seo,
}: {
    channel: PublicChannel;
    posts: { data: Post[] };
    seo: Seo;
}) {
    const meta = channelMeta(channel.slug);
    const signupHref = `/newsletters/${channel.signup_slug}/signup`;

    return (
        <PublicLayout>
            <Head title={channel.label}>
                <meta head-key="description" name="description" content={seo.description} />
                <link head-key="canonical" rel="canonical" href={seo.url} />
                <meta head-key="og:title" property="og:title" content={seo.title} />
                <meta head-key="og:description" property="og:description" content={seo.description} />
                <meta head-key="og:type" property="og:type" content="website" />
                <meta head-key="og:url" property="og:url" content={seo.url} />
                <meta head-key="og:image" property="og:image" content={seo.image} />
                <meta head-key="twitter:card" name="twitter:card" content="summary" />
                <meta head-key="twitter:title" name="twitter:title" content={seo.title} />
                <meta head-key="twitter:description" name="twitter:description" content={seo.description} />
                <meta head-key="twitter:image" name="twitter:image" content={seo.image} />
                <link head-key="rss" rel="alternate" type="application/rss+xml" title={seo.title} href={seo.rss} />
            </Head>
            <main id="main-content">
                <div className="px-5 py-12 sm:px-6">
                    <div className={`glass-panel mx-auto max-w-public rounded-card p-8 ${channelTintClass(channel.slug)}`}>
                        <p className="eyebrow-on-glass flex items-center gap-2">
                            <LineIcon name={meta?.icon ?? 'document'} className="h-4 w-4" />
                            Channel
                        </p>
                        <h1 className="mt-2 text-2xl font-bold text-on-glass sm:text-3xl">{channel.label}</h1>
                        {channel.description && <p className="mt-2 max-w-2xl text-on-glass-muted">{channel.description}</p>}
                        <div className="mt-6 flex flex-wrap items-center gap-3">
                            <Link href={signupHref} className="button-primary">
                                Subscribe to {channel.label}
                            </Link>
                            <a href={`/${channel.slug}/rss.xml`} className="text-sm font-semibold text-on-glass-muted hover:text-white hover:underline">
                                RSS feed
                            </a>
                        </div>
                    </div>
                </div>
                <div className="mx-auto max-w-public px-5 pb-12 sm:px-6">
                    {posts.data.length === 0 ? (
                        <p className="glass-panel rounded-card p-6 text-on-glass-muted">
                            No published stories in {channel.label} yet. Subscribe to hear when the first one is out.
                        </p>
                    ) : (
                        <ul className="grid gap-8 md:grid-cols-2">
                            {posts.data.map((post) => (
                                <li key={post.slug} className="glass-story-card">
                                    {post.hero_image && (
                                        <div className="mb-4 aspect-video overflow-hidden rounded-control border border-white/15">
                                            <ResponsiveHeroImage
                                                image={post}
                                                sizes="(min-width: 48rem) 34rem, 100vw"
                                                className="h-full w-full object-cover"
                                            />
                                        </div>
                                    )}
                                    <h2 className="text-lg font-bold text-on-glass">
                                        <Link href={`/articles/${post.slug}`} className="hover:text-brand-teal hover:underline">
                                            {post.title}
                                        </Link>
                                    </h2>
                                    {post.excerpt && <p className="mt-2 text-sm leading-6 text-on-glass-muted">{post.excerpt}</p>}
                                    <p className="mt-3 text-xs font-semibold text-on-glass-muted">{post.author}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </main>
        </PublicLayout>
    );
}
