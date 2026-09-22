import { Head } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

export type PublicPage = {
    title: string;
    slug: string;
    excerpt: string | null;
    html: string;
    author: string;
    published_at: string | null;
    meta_title: string;
    meta_description: string | null;
    hero_image: string | null;
    hero_image_alt: string | null;
    hero_image_caption: string | null;
    hero_image_credit: string | null;
    canonical_url: string | null;
    url: string;
};

export default function PageShow({
    page,
    preview = false,
}: {
    page: PublicPage;
    preview?: boolean;
}) {
    const canonical = page.canonical_url || page.url;

    return (
        <PublicLayout>
            <Head title={page.meta_title}>
                {page.meta_description && <meta name="description" content={page.meta_description} />}
                <link rel="canonical" href={canonical} />
            </Head>
            <article className="mx-auto max-w-3xl px-4 py-10">
                {preview && (
                    <p className="mb-4 rounded-control border border-warning bg-warning-mist px-3 py-2 text-sm text-warning">
                        Preview — not publicly published.
                    </p>
                )}
                <h1 className="text-3xl font-bold text-brand-ink">{page.title}</h1>
                {page.excerpt && <p className="mt-3 text-lg text-muted">{page.excerpt}</p>}
                {page.hero_image && (
                    <figure className="article-hero mt-6 overflow-hidden">
                        <img
                            src={page.hero_image}
                            alt={page.hero_image_alt ?? ''}
                            className="h-auto w-full max-w-full object-cover"
                            decoding="async"
                        />
                        {(page.hero_image_caption || page.hero_image_credit) && (
                            <figcaption className="mt-2 text-sm text-muted">
                                {page.hero_image_caption}
                                {page.hero_image_credit ? ` — ${page.hero_image_credit}` : ''}
                            </figcaption>
                        )}
                    </figure>
                )}
                <div
                    className="prose mt-8 max-w-none"
                    dangerouslySetInnerHTML={{ __html: page.html }}
                />
            </article>
        </PublicLayout>
    );
}
