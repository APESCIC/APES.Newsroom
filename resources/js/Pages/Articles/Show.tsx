import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { channelMeta } from '../../channelMeta';
import PublicLayout from '../../Components/Layout/PublicLayout';

type Comment = {
    id: number;
    body: string;
    created_at: string | null;
    author: { display_name: string; avatar_url: string | null };
};

type Reactions = {
    helpful: number;
    support: number;
    thank_you: number;
    mine: string[];
};

type ArticleTag = string | { name: string; slug: string };

export type Article = {
    title: string;
    slug: string;
    excerpt: string | null;
    html: string | null;
    visibility?: string;
    gated?: boolean;
    gate?: { reason: string | null; cta: { label: string; href: string } | null } | null;
    channel: string;
    channel_slug: string;
    author: string;
    author_id?: number;
    published_at: string | null;
    meta_title: string;
    meta_description: string | null;
    tags?: ArticleTag[];
    hero_image: string | null;
    hero_image_alt: string | null;
    hero_image_caption: string | null;
    hero_image_credit: string | null;
    canonical_url: string | null;
    url: string;
};

export function articleCanonicalUrl(article: Pick<Article, 'canonical_url' | 'url' | 'slug'>): string {
    return article.canonical_url || article.url || `/articles/${article.slug}`;
}

function articleStructuredData(article: Article, canonicalUrl: string): Record<string, unknown> {
    const data: Record<string, unknown> = {
        '@context': 'https://schema.org',
        '@type': 'Article',
        headline: article.title,
        author: { '@type': 'Person', name: article.author },
        datePublished: article.published_at,
        url: canonicalUrl,
    };

    if (article.hero_image) {
        data.image = article.hero_image;
    }

    return data;
}

function ArticleHero({ article }: { article: Article }) {
    if (!article.hero_image) {
        return null;
    }

    const caption = article.hero_image_caption?.trim() ?? '';
    const credit = article.hero_image_credit?.trim() ?? '';

    return (
        <figure className="article-hero mt-6 overflow-hidden">
            <img
                src={article.hero_image}
                alt={article.hero_image_alt ?? ''}
                className="h-auto w-full max-w-full object-cover"
                sizes="(min-width: 48rem) 48rem, 100vw"
                decoding="async"
            />
            {(caption || credit) && (
                <figcaption className="mt-2 text-sm text-muted">
                    {caption ? <p>{caption}</p> : null}
                    {credit ? <p className="image-credit">{credit}</p> : null}
                </figcaption>
            )}
        </figure>
    );
}

const reactionLabels: Record<string, string> = {
    helpful: 'Helpful',
    support: 'Support',
    thank_you: 'Thank You',
};

export default function ArticleShow({
    article,
    preview,
    comments = [],
    reactions = { helpful: 0, support: 0, thank_you: 0, mine: [] },
    canEngage = false,
    status,
}: {
    article: Article;
    preview?: boolean;
    comments?: Comment[];
    reactions?: Reactions;
    canEngage?: boolean;
    status?: string;
}) {
    const commentForm = useForm({ body: '' });

    const submitComment: FormEventHandler = (e) => {
        e.preventDefault();
        commentForm.post(`/articles/${article.slug}/comments`, {
            onSuccess: () => commentForm.reset('body'),
        });
    };

    const toggleReaction = (type: string) => {
        router.post(`/articles/${article.slug}/reactions`, { type }, { preserveScroll: true });
    };

    const canonicalUrl = articleCanonicalUrl(article);

    return (
        <PublicLayout>
            <Head title={article.meta_title}>
                <meta name="description" content={article.meta_description ?? ''} />
                {preview && <meta name="robots" content="noindex,nofollow" />}
                <link rel="canonical" href={canonicalUrl} />
                <meta property="og:title" content={article.meta_title} />
                <meta property="og:description" content={article.meta_description ?? ''} />
                <meta property="og:type" content="article" />
                <meta property="og:url" content={canonicalUrl} />
                {article.hero_image && <meta property="og:image" content={article.hero_image} />}
                <meta name="twitter:card" content={article.hero_image ? 'summary_large_image' : 'summary'} />
                <meta name="twitter:title" content={article.meta_title} />
                <meta name="twitter:description" content={article.meta_description ?? ''} />
                <meta name="twitter:url" content={canonicalUrl} />
                {article.hero_image && <meta name="twitter:image" content={article.hero_image} />}
                <script type="application/ld+json">{JSON.stringify(articleStructuredData(article, canonicalUrl))}</script>
            </Head>
            {preview && (
                <div className="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">Preview — not indexed</div>
            )}
            <main id="main-content" className="mx-auto max-w-3xl px-5 py-12 sm:px-6">
                <div className="glass-form-panel">
                    <span className={`inline-flex rounded-control px-2 py-1 text-[0.625rem] font-bold tracking-wide uppercase ${channelMeta(article.channel_slug)?.badgeClass ?? 'bg-brand-mist text-teal-deep'}`}>
                        {article.channel}
                    </span>
                    <article>
                        <h1 className="display-headline mt-4">{article.title}</h1>
                        <p className="mt-2 text-sm text-muted">
                            By{' '}
                            {article.author_id ? (
                                <Link href={`/authors/${article.author_id}`} className="text-teal-deep hover:underline">
                                    {article.author}
                                </Link>
                            ) : (
                                article.author
                            )}
                            {article.published_at && (
                                <>
                                    {' '}
                                    ·{' '}
                                    <Link
                                        href={`/archive/${new Date(article.published_at).getUTCFullYear()}`}
                                        className="text-teal-deep hover:underline"
                                    >
                                        {new Date(article.published_at).toLocaleDateString('en-GB')}
                                    </Link>
                                </>
                            )}
                        </p>
                        {article.tags && article.tags.length > 0 && (
                            <ul className="mt-3 flex flex-wrap gap-2 text-sm">
                                {article.tags.map((tag) => {
                                    const name = typeof tag === 'string' ? tag : tag.name;
                                    const slug = typeof tag === 'string' ? tag.toLowerCase().replace(/\s+/g, '-') : tag.slug;
                                    return (
                                        <li key={slug}>
                                            <Link href={`/tags/${slug}`} className="rounded-control border border-border px-2 py-0.5 text-teal-deep hover:underline">
                                                {name}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <ArticleHero article={article} />
                        {article.gated ? (
                            <div className="glass-form-panel mt-8" role="status">
                                <p className="text-body">
                                    {article.gate?.reason === 'paid'
                                        ? 'This article is for paid members.'
                                        : 'This article is for members.'}
                                </p>
                                {article.excerpt && <p className="mt-2 text-sm text-muted">{article.excerpt}</p>}
                                {article.gate?.cta && (
                                    <a href={article.gate.cta.href} className="button-primary mt-4 inline-flex">
                                        {article.gate.cta.label}
                                    </a>
                                )}
                            </div>
                        ) : (
                            <div
                                className="prose mt-8 max-w-none"
                                dangerouslySetInnerHTML={{ __html: article.html ?? '' }}
                            />
                        )}
                    </article>

                    {!preview && (
                        <section className="mt-12 border-t border-border pt-8" aria-label="Reactions">
                            <h2 className="text-lg font-bold text-body">Reactions</h2>
                            <div className="mt-3 flex flex-wrap gap-3">
                                {Object.entries(reactionLabels).map(([type, label]) => {
                                    const active = reactions.mine.includes(type);
                                    const count = reactions[type as keyof Omit<Reactions, 'mine'>] ?? 0;
                                    return (
                                        <button
                                            key={type}
                                            type="button"
                                            disabled={!canEngage}
                                            onClick={() => toggleReaction(type)}
                                            aria-pressed={active}
                                            className={`min-h-11 rounded-control border px-3 py-1.5 text-sm ${active ? 'border-apes-primary bg-apes-mist text-apes-primary' : 'border-border text-body'}`}
                                        >
                                            {label} <span className="tabular-nums">({count})</span>
                                        </button>
                                    );
                                })}
                            </div>
                            {!canEngage && (
                                <p className="mt-2 text-sm text-muted">
                                    <Link href="/login" className="text-teal-deep hover:underline">
                                        Sign in
                                    </Link>{' '}
                                    with a verified account to react.
                                </p>
                            )}
                        </section>
                    )}

                    {!preview && (
                        <section className="mt-10 border-t border-border pt-8" aria-label="Comments">
                            <h2 className="text-lg font-bold text-body">Comments</h2>
                            {status === 'comment-pending' && (
                                <p className="status-badge-success mt-2">Thanks — your comment is awaiting moderation.</p>
                            )}

                            <ul className="mt-4 flex flex-col gap-4">
                                {comments.map((comment) => (
                                    <li key={comment.id} className="border-b border-border pb-4">
                                        <p className="text-sm font-bold text-body">{comment.author.display_name}</p>
                                        <p className="mt-1 text-body">{comment.body}</p>
                                        {canEngage && (
                                            <button
                                                type="button"
                                                className="mt-2 text-xs text-muted hover:underline"
                                                onClick={() => {
                                                    const reason = window.prompt('Why are you reporting this comment?');
                                                    if (!reason) {
                                                        return;
                                                    }
                                                    router.post('/reports', {
                                                        type: 'comment',
                                                        id: comment.id,
                                                        reason,
                                                    });
                                                }}
                                            >
                                                Report
                                            </button>
                                        )}
                                    </li>
                                ))}
                                {comments.length === 0 && <li className="text-sm text-muted">No approved comments yet.</li>}
                            </ul>

                            {canEngage ? (
                                <form onSubmit={submitComment} className="mt-6 flex flex-col gap-3">
                                    <label htmlFor="comment-body" className="text-sm font-bold text-body">
                                        Add a comment
                                    </label>
                                    <textarea
                                        id="comment-body"
                                        value={commentForm.data.body}
                                        onChange={(e) => commentForm.setData('body', e.target.value)}
                                        rows={3}
                                        required
                                        maxLength={2000}
                                        className="form-input"
                                    />
                                    {commentForm.errors.body && (
                                        <p className="text-sm text-danger">{commentForm.errors.body}</p>
                                    )}
                                    <button
                                        type="submit"
                                        disabled={commentForm.processing}
                                        className="button-primary w-fit"
                                    >
                                        Submit for moderation
                                    </button>
                                </form>
                            ) : (
                                <p className="mt-4 text-sm text-muted">
                                    <Link href="/login" className="text-teal-deep hover:underline">
                                        Sign in
                                    </Link>{' '}
                                    with a verified account to comment.
                                </p>
                            )}
                        </section>
                    )}
                </div>
            </main>
        </PublicLayout>
    );
}
