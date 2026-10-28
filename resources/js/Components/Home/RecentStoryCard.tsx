import { Link } from '@inertiajs/react';
import { channelMeta } from '../../channelMeta';
import LineIcon from '../Icons/LineIcon';
import ResponsiveHeroImage from '../Media/ResponsiveHeroImage';
import { formatStoryDate, type PostCard } from './DeskPanel';

type RecentStoryCardProps = {
    post: PostCard;
    variant?: 'default' | 'lead';
};

export default function RecentStoryCard({ post }: RecentStoryCardProps) {
    const meta = channelMeta(post.channel_slug);
    const published = formatStoryDate(post.published_at);

    return (
        <article className="sg-card group p-5">
            <div
                className={`flex aspect-video items-center justify-center overflow-hidden rounded-sg-field border border-white/85 ${
                    meta?.mediaClass ?? 'bg-sg-info-mist text-sg-teal'
                }`}
            >
                {post.hero_image ? (
                    <ResponsiveHeroImage
                        image={post}
                        sizes="(min-width: 64rem) 22rem, (min-width: 48rem) 45vw, 100vw"
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <LineIcon name={meta?.icon ?? 'document'} className="h-16 w-16" />
                )}
            </div>
            <span className={`mt-4 inline-flex rounded-full px-2.5 py-1 text-[0.625rem] font-bold tracking-wide uppercase ${meta?.badgeClass ?? 'bg-sg-info-mist text-sg-info'}`}>
                {meta?.label ?? post.channel}
            </span>
            <h3 className="mt-2 text-lg leading-snug font-bold text-sg-ink">
                <Link href={`/articles/${post.slug}`} className="group-hover:text-sg-teal hover:underline">
                    {post.title}
                </Link>
            </h3>
            {post.excerpt && (
                <p className="mt-3 text-sm leading-6 text-muted">{post.excerpt}</p>
            )}
            {published && (
                <time dateTime={post.published_at ?? undefined} className="mt-3 block text-xs font-semibold text-muted">
                    {published}
                </time>
            )}
        </article>
    );
}
