import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef } from 'react';
import type { OutputData } from '@editorjs/editorjs';
import EditorJsField from '../../../Components/editor/EditorJsField';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type PageData = {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    content: OutputData;
    status: string;
    hero_image: string | null;
    hero_image_alt: string | null;
    hero_image_caption: string | null;
    hero_image_credit: string | null;
    meta_title: string | null;
    meta_description: string | null;
    canonical_url: string | null;
    updated_at: string | null;
};

const emptyContent: OutputData = {
    time: Date.now(),
    blocks: [{ type: 'paragraph', data: { text: '' } }],
    version: '2.29.0',
};

type PageForm = {
    title: string;
    slug: string;
    excerpt: string;
    content: OutputData;
    hero_image: string;
    hero_image_alt: string;
    hero_image_caption: string;
    hero_image_credit: string;
    meta_title: string;
    meta_description: string;
    canonical_url: string;
    expected_updated_at: string;
};

type FormErrors = Record<string, string>;

function formFromPage(page: PageData | null): PageForm {
    return {
        title: page?.title ?? '',
        slug: page?.slug ?? '',
        excerpt: page?.excerpt ?? '',
        content: page?.content ?? emptyContent,
        hero_image: page?.hero_image ?? '',
        hero_image_alt: page?.hero_image_alt ?? '',
        hero_image_caption: page?.hero_image_caption ?? '',
        hero_image_credit: page?.hero_image_credit ?? '',
        meta_title: page?.meta_title ?? '',
        meta_description: page?.meta_description ?? '',
        canonical_url: page?.canonical_url ?? '',
        expected_updated_at: page?.updated_at ?? '',
    };
}

export default function PagesEdit({ page }: { page: PageData | null }) {
    const inertiaPage = usePage();
    const pageErrors = (inertiaPage.props as { errors?: FormErrors }).errors ?? {};
    const { data, setData, post, patch, processing, errors: formErrors, transform } = useForm<PageForm>(formFromPage(page));
    const errors: FormErrors = { ...pageErrors, ...(formErrors as FormErrors) };
    const titleTouchedSlug = useRef(false);

    useEffect(() => {
        if (page || titleTouchedSlug.current || data.slug !== '') {
            return;
        }
        const slug = data.title
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
        setData('slug', slug);
    }, [data.title, data.slug, page, setData]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        transform((form) => form);
        if (page) {
            patch(`/staff/pages/${page.id}`, { preserveScroll: true });
        } else {
            post('/staff/pages');
        }
    };

    const action = (url: string) => router.post(url, {}, { preserveScroll: true });

    return (
        <WorkspaceLayout
            area="Staff"
            active="pages"
            title={page ? 'Edit page' : 'New page'}
            subtitle="Pages stay out of news lists and RSS"
        >
            <Head title={page ? `Edit: ${page.title}` : 'New page'} />
            <div className="mb-4">
                <Link href="/staff/pages" className="text-sm text-teal-deep hover:underline">
                    ← Back to pages
                </Link>
                {page && (
                    <span className="ml-4 text-sm">
                        <Link href={`/staff/pages/${page.id}/preview`} className="underline">
                            Preview
                        </Link>
                        {page.status === 'published' && (
                            <>
                                {' · '}
                                <a href={`/pages/${page.slug}`} className="underline" target="_blank" rel="noreferrer">
                                    Public URL
                                </a>
                            </>
                        )}
                    </span>
                )}
            </div>

            {errors.conflict && (
                <p className="mb-4 rounded-control border border-danger bg-warning-mist px-3 py-2 text-sm text-danger">
                    {errors.conflict}
                </p>
            )}

            <form onSubmit={submit} className="space-y-6">
                <div className="grid gap-4 md:grid-cols-2">
                    <label className="block text-sm">
                        <span className="font-semibold">Title</span>
                        <input
                            className="mt-1 w-full rounded-control border border-border px-3 py-2"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            required
                        />
                        {errors.title && <span className="text-xs text-danger">{errors.title}</span>}
                    </label>
                    <label className="block text-sm">
                        <span className="font-semibold">Slug</span>
                        <input
                            className="mt-1 w-full rounded-control border border-border px-3 py-2"
                            value={data.slug}
                            onChange={(e) => {
                                titleTouchedSlug.current = true;
                                setData('slug', e.target.value);
                            }}
                        />
                        {errors.slug && <span className="text-xs text-danger">{errors.slug}</span>}
                    </label>
                </div>

                <label className="block text-sm">
                    <span className="font-semibold">Excerpt</span>
                    <textarea
                        className="mt-1 w-full rounded-control border border-border px-3 py-2"
                        rows={2}
                        value={data.excerpt}
                        onChange={(e) => setData('excerpt', e.target.value)}
                    />
                </label>

                <div>
                    <p className="mb-2 text-sm font-semibold">Body</p>
                    <EditorJsField
                        initialData={data.content}
                        onChange={(next) => setData('content', next)}
                    />
                    {errors.content && <span className="text-xs text-danger">{errors.content}</span>}
                </div>

                <fieldset className="grid gap-3 md:grid-cols-2">
                    <legend className="mb-1 text-sm font-semibold">Hero & SEO</legend>
                    {(
                        [
                            ['hero_image', 'Hero image URL'],
                            ['hero_image_alt', 'Hero alt'],
                            ['meta_title', 'Meta title'],
                            ['canonical_url', 'Canonical URL'],
                        ] as const
                    ).map(([key, label]) => (
                        <label key={key} className="block text-sm">
                            <span>{label}</span>
                            <input
                                className="mt-1 w-full rounded-control border border-border px-3 py-2"
                                value={data[key]}
                                onChange={(e) => setData(key, e.target.value)}
                            />
                        </label>
                    ))}
                    <label className="block text-sm md:col-span-2">
                        <span>Meta description</span>
                        <textarea
                            className="mt-1 w-full rounded-control border border-border px-3 py-2"
                            rows={2}
                            value={data.meta_description}
                            onChange={(e) => setData('meta_description', e.target.value)}
                        />
                    </label>
                </fieldset>

                <div className="flex flex-wrap gap-3">
                    <button type="submit" className="button-primary" disabled={processing}>
                        {page ? 'Save' : 'Create draft'}
                    </button>
                    {page && page.status !== 'published' && (
                        <button
                            type="button"
                            className="button-secondary"
                            onClick={() => action(`/staff/pages/${page.id}/publish`)}
                        >
                            Publish
                        </button>
                    )}
                    {page && page.status === 'published' && (
                        <button
                            type="button"
                            className="button-secondary"
                            onClick={() => action(`/staff/pages/${page.id}/unpublish`)}
                        >
                            Unpublish
                        </button>
                    )}
                    {page && (
                        <button
                            type="button"
                            className="button-danger"
                            onClick={() => router.delete(`/staff/pages/${page.id}`)}
                        >
                            Delete
                        </button>
                    )}
                </div>
            </form>
        </WorkspaceLayout>
    );
}
