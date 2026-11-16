import type { OutputData } from '@editorjs/editorjs';

const TITLE_MAX = 60;
const DESCRIPTION_MAX = 155;
const SITE_SUFFIX = ' | APES Newsroom';

function plainText(value: string): string {
    return value
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function firstBodyParagraph(content: OutputData | null | undefined): string {
    for (const block of content?.blocks ?? []) {
        const type = block.type;
        if (!['paragraph', 'header', 'callout', 'quote'].includes(type)) {
            continue;
        }
        const text = plainText(String((block.data as { text?: string })?.text ?? ''));
        if (text) {
            return text;
        }
    }
    return '';
}

export function autoMetaTitle(title: string): string {
    let base = plainText(title) || 'APES Newsroom';
    if (base.length > TITLE_MAX) {
        base = `${base.slice(0, TITLE_MAX - 1)}…`;
    }
    return `${base}${SITE_SUFFIX}`;
}

export function autoMetaDescription(excerpt: string, content?: OutputData | null): string {
    let text = plainText(excerpt);
    if (!text) {
        text = firstBodyParagraph(content);
    }
    if (!text) {
        return '';
    }
    if (text.length <= DESCRIPTION_MAX) {
        return text;
    }
    return `${text.slice(0, DESCRIPTION_MAX - 1)}…`;
}

type SeoValues = {
    meta_title: string;
    meta_description: string;
    canonical_url: string;
    seo_noindex: boolean;
    og_title: string;
    og_description: string;
    og_image: string;
    twitter_title: string;
    twitter_description: string;
    twitter_image: string;
};

type Props = {
    values: SeoValues;
    errors: Record<string, string | undefined>;
    title: string;
    excerpt: string;
    content?: OutputData | null;
    publicUrl: string;
    heroImage?: string | null;
    onChange: <K extends keyof SeoValues>(key: K, value: SeoValues[K]) => void;
};

function Counter({ value, max }: { value: string; max: number }) {
    const length = value.length;
    return (
        <span className={`text-xs ${length > max ? 'text-danger' : 'text-muted'}`}>
            {length}/{max}
        </span>
    );
}

function PreviewCard({
    label,
    title,
    description,
    image,
    url,
}: {
    label: string;
    title: string;
    description: string;
    image: string;
    url: string;
}) {
    return (
        <div className="overflow-hidden rounded-md border border-border bg-(--sg-surface)">
            <p className="border-b border-border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted">{label}</p>
            {image ? (
                <img src={image} alt="" className="aspect-[1.91/1] w-full object-cover bg-sg-info-mist" />
            ) : (
                <div className="flex aspect-[1.91/1] items-center justify-center bg-sg-info-mist text-xs text-muted">No image</div>
            )}
            <div className="space-y-1 p-3">
                <p className="truncate text-xs text-muted">{url}</p>
                <p className="line-clamp-2 text-sm font-semibold text-body">{title || 'Title'}</p>
                <p className="line-clamp-3 text-xs text-muted">{description || 'Description'}</p>
            </div>
        </div>
    );
}

export default function SeoFields({
    values,
    errors,
    title,
    excerpt,
    content,
    publicUrl,
    heroImage,
    onChange,
}: Props) {
    const generatedTitle = autoMetaTitle(title);
    const generatedDescription = autoMetaDescription(excerpt, content);

    const displayTitle = values.meta_title || generatedTitle;
    const displayDescription = values.meta_description || generatedDescription;
    const ogTitle = values.og_title || displayTitle;
    const ogDescription = values.og_description || displayDescription;
    const ogImage = values.og_image || heroImage || '';
    const twitterTitle = values.twitter_title || ogTitle;
    const twitterDescription = values.twitter_description || ogDescription;
    const twitterImage = values.twitter_image || ogImage;

    return (
        <fieldset className="flex flex-col gap-4 border-t border-border/60 pt-4">
            <legend className="font-medium">SEO and social</legend>
            <p className="text-sm text-muted">
                Leave a field blank to use the automatic value. Clearing an override restores the generated title or description.
            </p>

            <label className="flex flex-col gap-1 text-sm">
                <span className="flex items-center justify-between font-medium">
                    Meta title
                    <Counter value={displayTitle} max={TITLE_MAX + SITE_SUFFIX.length} />
                </span>
                <input
                    className="form-input"
                    placeholder="Meta title"
                    value={values.meta_title}
                    onChange={(e) => onChange('meta_title', e.target.value)}
                />
                <span className="text-xs text-muted">Automatic: {generatedTitle}</span>
                {errors.meta_title && <span className="text-sm text-danger">{errors.meta_title}</span>}
            </label>

            <label className="flex flex-col gap-1 text-sm">
                <span className="flex items-center justify-between font-medium">
                    Meta description
                    <Counter value={displayDescription} max={DESCRIPTION_MAX} />
                </span>
                <textarea
                    className="form-input"
                    rows={3}
                    placeholder="Meta description"
                    value={values.meta_description}
                    onChange={(e) => onChange('meta_description', e.target.value)}
                />
                <span className="text-xs text-muted">
                    Automatic: {generatedDescription || 'from the excerpt or first paragraph'}
                </span>
                {errors.meta_description && <span className="text-sm text-danger">{errors.meta_description}</span>}
            </label>

            <label className="flex flex-col gap-1 text-sm">
                <span className="font-medium">Canonical URL</span>
                <input
                    className="form-input"
                    placeholder="Canonical URL"
                    value={values.canonical_url}
                    onChange={(e) => onChange('canonical_url', e.target.value)}
                />
                <span className="text-xs text-muted">Default: {publicUrl}</span>
                {errors.canonical_url && <span className="text-sm text-danger">{errors.canonical_url}</span>}
            </label>

            <label className="inline-flex min-h-11 items-center gap-2 text-sm font-medium">
                <input
                    type="checkbox"
                    checked={values.seo_noindex}
                    onChange={(e) => onChange('seo_noindex', e.target.checked)}
                />
                Ask search engines not to index this page (noindex)
            </label>

            <div className="grid gap-3 sm:grid-cols-2">
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Open Graph title</span>
                    <input className="form-input" placeholder={displayTitle} value={values.og_title} onChange={(e) => onChange('og_title', e.target.value)} />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">X title</span>
                    <input className="form-input" placeholder={ogTitle} value={values.twitter_title} onChange={(e) => onChange('twitter_title', e.target.value)} />
                </label>
                <label className="flex flex-col gap-1 text-sm sm:col-span-2">
                    <span className="font-medium">Open Graph description</span>
                    <textarea className="form-input" rows={2} placeholder={displayDescription} value={values.og_description} onChange={(e) => onChange('og_description', e.target.value)} />
                </label>
                <label className="flex flex-col gap-1 text-sm sm:col-span-2">
                    <span className="font-medium">X description</span>
                    <textarea className="form-input" rows={2} placeholder={ogDescription} value={values.twitter_description} onChange={(e) => onChange('twitter_description', e.target.value)} />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Open Graph image URL</span>
                    <input className="form-input" placeholder={heroImage ?? 'Hero social crop or branded fallback'} value={values.og_image} onChange={(e) => onChange('og_image', e.target.value)} />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">X image URL</span>
                    <input className="form-input" placeholder={ogImage || 'Same as Open Graph'} value={values.twitter_image} onChange={(e) => onChange('twitter_image', e.target.value)} />
                </label>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <PreviewCard label="Google preview" title={displayTitle} description={displayDescription} image={ogImage} url={values.canonical_url || publicUrl} />
                <PreviewCard label="Social card preview" title={twitterTitle} description={twitterDescription} image={twitterImage || ogImage} url={values.canonical_url || publicUrl} />
            </div>
        </fieldset>
    );
}
