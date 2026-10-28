import { Head } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';
import ResponsiveHeroImage, { type HeroImageFields } from '../../Components/Media/ResponsiveHeroImage';

export type PublicPage = HeroImageFields & {
    title: string;
    slug: string;
    excerpt: string | null;
    html: string | null;
    visibility?: string;
    gated?: boolean;
    gate?: { reason: string | null; cta: { label: string; href: string } | null } | null;
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
    const socialImage = page.hero_image ? (page.hero_image_social ?? page.hero_image) : null;

    return (
        <PublicLayout>
            <Head title={page.meta_title}>
                {page.meta_description && <meta name="description" content={page.meta_description} />}
                <link rel="canonical" href={canonical} />
                {socialImage && <meta property="og:image" content={socialImage} />}
                {socialImage && <meta name="twitter:image" content={socialImage} />}
            </Head>
            <article className="mx-auto max-w-3xl px-4 py-10">
                {preview && (
                    <p className="mb-4 rounded-sg-field border border-warning/40 bg-warning-mist px-3 py-2 text-sm text-warning">
                        Preview — not publicly published.
                    </p>
                )}
                <h1 className="text-3xl font-bold text-brand-ink">{page.title}</h1>
                {page.excerpt && <p className="mt-3 text-lg text-muted">{page.excerpt}</p>}
                {page.hero_image && (
                    <figure className="article-hero mt-6 overflow-hidden">
                        <ResponsiveHeroImage
                            image={page}
                            sizes="(min-width: 48rem) 48rem, 100vw"
                            className="h-auto w-full max-w-full object-cover"
                            priority
                        />
                        {(page.hero_image_caption || page.hero_image_credit) && (
                            <figcaption className="mt-2 text-sm text-muted">
                                {page.hero_image_caption}
                                {page.hero_image_credit ? ` — ${page.hero_image_credit}` : ''}
                            </figcaption>
                        )}
                    </figure>
                )}
                {page.gated ? (
                    <div className="form-panel mt-8" role="status">
                        <p className="text-body">
                            {page.gate?.reason === 'paid'
                                ? 'This page is for paid members.'
                                : 'This page is for members.'}
                        </p>
                        {page.excerpt && <p className="mt-2 text-sm text-muted">{page.excerpt}</p>}
                        {page.gate?.cta && (
                            <a href={page.gate.cta.href} className="button-primary mt-4 inline-flex">
                                {page.gate.cta.label}
                            </a>
                        )}
                    </div>
                ) : (
                    <div
                        className="prose mt-8 max-w-none"
                        dangerouslySetInnerHTML={{ __html: page.html ?? '' }}
                    />
                )}
            </article>
        </PublicLayout>
    );
}
