import { Link } from '@inertiajs/react';
import { channelMeta } from '../../channelMeta';
import LineIcon from '../Icons/LineIcon';
import type { HeroImageFields } from '../Media/ResponsiveHeroImage';

export type PostCard = HeroImageFields & {
    title: string;
    slug: string;
    excerpt: string | null;
    channel: string;
    channel_slug: string;
    author: string;
    published_at: string | null;
};

export function formatStoryDate(value: string | null) {
    if (!value) {
        return null;
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Europe/London',
    }).format(new Date(value));
}

export default function DeskPanel({ featured }: { featured?: PostCard }) {
    if (!featured) {
        return (
            <section className="flex min-h-80 items-center">
                <div>
                    <p className="eyebrow">Featured story</p>
                    <h1 className="display-headline mt-3">News from across APES</h1>
                    <p className="mt-4 max-w-xl text-muted">No published stories yet. Please check back soon.</p>
                </div>
            </section>
        );
    }

    const meta = channelMeta(featured.channel_slug);
    const published = formatStoryDate(featured.published_at);

    return (
        <article className="max-w-[45rem]">
            <p className={`eyebrow flex items-center gap-2 ${meta?.textClass ?? ''}`}>
                <LineIcon name={meta?.icon ?? 'document'} className="h-4 w-4" />
                {featured.channel}
            </p>
            <h1 className="display-headline mt-4">
                <Link href={`/articles/${featured.slug}`} className="hover:text-sg-teal hover:underline">
                    {featured.title}
                </Link>
            </h1>
            {featured.excerpt && <p className="mt-6 text-lg leading-8 text-muted">{featured.excerpt}</p>}
            <div className="mt-8 flex flex-col gap-6 sm:flex-row sm:items-center">
                <Link
                    href={`/articles/${featured.slug}`}
                    className="button-primary px-8"
                    aria-label={`Read the story: ${featured.title}`}
                >
                    Read the story
                </Link>
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-full border border-white/85 bg-white/70 text-sg-teal">
                        <LineIcon name="user" className="h-5 w-5" />
                    </span>
                    <p className="text-sm font-bold text-sg-ink">
                        {featured.author}
                        {published && <span className="mt-1 block text-xs font-normal text-muted">{published}</span>}
                    </p>
                </div>
            </div>
        </article>
    );
}
