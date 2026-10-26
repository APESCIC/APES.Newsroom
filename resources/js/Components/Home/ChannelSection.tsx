import { Link } from '@inertiajs/react';
import { useId } from 'react';
import { channelMeta, channelTintClass } from '../../channelMeta';
import type { PublicChannel } from '../../types/page';
import LineIcon from '../Icons/LineIcon';
import { formatStoryDate } from './DeskPanel';

export type HomeChannel = PublicChannel & {
    latest: Array<{ title: string; slug: string; published_at: string | null }>;
};

export default function ChannelSection({ channel }: { channel: HomeChannel }) {
    const meta = channelMeta(channel.slug);
    const headingId = useId();

    return (
        <article className={`glass-channel ${channelTintClass(channel.slug)}`} aria-labelledby={headingId}>
            <div className="flex items-center gap-3">
                <span className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-control bg-white/10 ${meta?.textClass ?? 'text-brand-teal'}`}>
                    <LineIcon name={meta?.icon ?? 'document'} className="h-7 w-7" />
                </span>
                <h3 id={headingId} className="text-xl font-bold text-on-glass">
                    <Link href={`/${channel.slug}`} className="hover:text-brand-teal hover:underline">
                        {channel.label}
                    </Link>
                </h3>
            </div>
            {channel.latest.length > 0 ? (
                <ul className="mt-4 space-y-3" aria-label={`Latest from ${channel.label}`}>
                    {channel.latest.map((post) => {
                        const published = formatStoryDate(post.published_at);

                        return (
                            <li key={post.slug}>
                                <Link href={`/articles/${post.slug}`} className="text-sm leading-6 font-semibold text-on-glass hover:text-brand-teal hover:underline">
                                    {post.title}
                                </Link>
                                {published && (
                                    <time dateTime={post.published_at ?? undefined} className="block text-xs text-on-glass-muted">
                                        {published}
                                    </time>
                                )}
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <p className="mt-4 text-sm leading-6 text-on-glass-muted">
                    {channel.description ?? `Stories from ${channel.label} are coming soon.`}
                </p>
            )}
            <div className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 pt-5 text-sm font-semibold">
                <Link
                    href={`/newsletters/${channel.signup_slug}/signup`}
                    className="inline-flex min-h-11 items-center rounded-control border border-white/25 px-3 text-white hover:bg-white/10"
                    aria-label={`Subscribe to ${channel.label}`}
                >
                    Subscribe
                </Link>
                {channel.latest.length > 0 && (
                    <Link
                        href={`/${channel.slug}`}
                        className="inline-flex min-h-11 items-center gap-1 text-on-glass-muted hover:text-white"
                        aria-label={`All stories from ${channel.label}`}
                    >
                        All stories
                        <LineIcon name="arrow-right" className="h-4 w-4" />
                    </Link>
                )}
            </div>
        </article>
    );
}
