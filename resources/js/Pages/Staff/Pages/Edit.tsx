import { router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Eye, FileStack, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';
import type { OutputData } from '@editorjs/editorjs';
import EditorField from '../../../Components/editor/EditorField';
import EditorSessionNotice from '../../../Components/editor/EditorSessionNotice';
import HeroImageField from '../../../Components/Staff/HeroImageField';
import MediaLibraryPicker from '../../../Components/Staff/MediaLibraryPicker';
import { PostStatusBadge } from '../../../Components/Staff/PostBadges';
import type { HeroMediaAsset, HeroUploadLimits } from '../../../Components/Staff/heroImageUpload';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import ActionMenu from '../../../Components/Workspace/ui/ActionMenu';
import ConfirmDialog from '../../../Components/Workspace/ui/ConfirmDialog';
import PillButton from '../../../Components/Workspace/ui/PillButton';
import type { EditorDriver } from '../../../types/editor';
import type { SharedPageProps } from '../../../types/page';

type PageData = {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    content: OutputData;
    content_html?: string | null;
    status: string;
    visibility?: string;
    hero_image: string | null;
    hero_image_alt: string | null;
    hero_image_caption: string | null;
    hero_image_credit: string | null;
    hero_media_id?: number | null;
    hero_focal_x?: number | null;
    hero_focal_y?: number | null;
    hero_media?: HeroMediaAsset | null;
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
    editor: EditorDriver;
    content: OutputData;
    content_html: string;
    visibility: string;
    hero_image: string;
    hero_image_alt: string;
    hero_image_caption: string;
    hero_image_credit: string;
    hero_media_id: number | null;
    hero_focal_x: number | null;
    hero_focal_y: number | null;
    meta_title: string;
    meta_description: string;
    canonical_url: string;
    expected_updated_at: string;
};

type FormErrors = Record<string, string>;

function formFromPage(page: PageData | null, editor: EditorDriver): PageForm {
    return {
        title: page?.title ?? '',
        slug: page?.slug ?? '',
        excerpt: page?.excerpt ?? '',
        editor,
        content: page?.content ?? emptyContent,
        content_html: page?.content_html ?? '',
        visibility: page?.visibility ?? 'public',
        hero_image: page?.hero_image ?? '',
        hero_image_alt: page?.hero_image_alt ?? '',
        hero_image_caption: page?.hero_image_caption ?? '',
        hero_image_credit: page?.hero_image_credit ?? '',
        hero_media_id: page?.hero_media_id ?? null,
        hero_focal_x: page?.hero_focal_x ?? null,
        hero_focal_y: page?.hero_focal_y ?? null,
        meta_title: page?.meta_title ?? '',
        meta_description: page?.meta_description ?? '',
        canonical_url: page?.canonical_url ?? '',
        expected_updated_at: page?.updated_at ?? '',
    };
}

function FieldError({ message }: { message?: string }) {
    return message ? <span className="mt-1 block text-xs text-danger">{message}</span> : null;
}

export default function PagesEdit({ page, heroUpload }: { page: PageData | null; heroUpload?: HeroUploadLimits }) {
    const inertiaPage = usePage<SharedPageProps>();
    const pageErrors = (inertiaPage.props as { errors?: FormErrors }).errors ?? {};
    const siteEditor = inertiaPage.props.editorDriver ?? 'editorjs';
    const [sessionEditor] = useState<EditorDriver>(siteEditor);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const { data, setData, post, patch, processing, errors: formErrors, transform } = useForm<PageForm>(
        formFromPage(page, sessionEditor),
    );
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
        transform(({ content, content_html: html, ...form }) => ({
            ...form,
            ...(form.editor === 'tinymce' ? { content_html: html } : { content }),
        }));
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
            documentTitle={page ? `Edit: ${page.title}` : undefined}
            subtitle={page ? page.title : 'Pages stay out of news lists and RSS'}
            actions={
                <>
                    <PillButton href="/staff/pages" variant="secondary" icon={FileStack}>
                        Pages
                    </PillButton>
                    {page && (
                        <PillButton href={`/staff/pages/${page.id}/preview`} variant="secondary" icon={Eye}>
                            Preview
                        </PillButton>
                    )}
                    {page?.status === 'published' && (
                        <a href={`/pages/${page.slug}`} className="sg-btn sg-btn-secondary" target="_blank" rel="noreferrer">
                            <ExternalLink aria-hidden="true" className="h-4 w-4" />
                            Public URL
                        </a>
                    )}
                </>
            }
        >

            {errors.conflict && (
                <p className="sg-card border-l-4 !border-l-[var(--color-sg-error)] px-4 py-3 text-sm text-danger" role="alert">
                    {errors.conflict}
                </p>
            )}

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <div className="sg-card flex min-w-0 flex-col gap-4 p-5 sm:p-6">
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="block text-sm font-semibold text-body">
                            Title
                            <input className="form-input mt-1.5" value={data.title} onChange={(e) => setData('title', e.target.value)} required />
                            <FieldError message={errors.title} />
                        </label>
                        <label className="block text-sm font-semibold text-body">
                            Slug
                            <input
                                className="form-input mt-1.5"
                                value={data.slug}
                                onChange={(e) => {
                                    titleTouchedSlug.current = true;
                                    setData('slug', e.target.value);
                                }}
                            />
                            <FieldError message={errors.slug} />
                        </label>
                    </div>

                    <label className="block text-sm font-semibold text-body">
                        Excerpt
                        <textarea className="form-input mt-1.5" rows={2} value={data.excerpt} onChange={(e) => setData('excerpt', e.target.value)} />
                    </label>

                    <div>
                        <p className="mb-2 text-sm font-semibold text-body">Body</p>
                        <EditorSessionNotice sessionEditor={sessionEditor} siteEditor={siteEditor} />
                        <div className="rounded-xl border border-border bg-white/80 p-3">
                            <EditorField
                                driver={sessionEditor}
                                initialData={data.content}
                                initialHtml={data.content_html}
                                onChange={(next) => setData('content', next)}
                                onHtmlChange={(html) => setData('content_html', html)}
                            />
                        </div>
                        <FieldError message={errors.content ?? errors.content_html ?? errors.editor} />
                    </div>

                    <HeroImageField
                        idPrefix="page-hero"
                        value={data}
                        asset={page?.hero_media ?? null}
                        limits={heroUpload}
                        errors={errors}
                        onChange={(patch) => setData((current) => ({ ...current, ...patch }))}
                        libraryPicker={(onPick) => <MediaLibraryPicker onPick={onPick} />}
                    />
                </div>

                <aside className="sg-card workspace-composer-panel flex flex-col gap-4 p-5" data-testid="page-publish-panel">
                    <div>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-base font-semibold text-body">Publish</h2>
                            {page && (
                                <ActionMenu
                                    label="More page actions"
                                    actions={[{ label: 'Delete page', icon: Trash2, tone: 'danger', onSelect: () => setConfirmDelete(true) }]}
                                />
                            )}
                        </div>
                        <p className="mt-1 flex items-center gap-2 text-sm text-muted">
                            Status: {page ? <PostStatusBadge status={page.status} /> : <span className="font-medium text-body">Not saved yet</span>}
                        </p>
                        <div className="mt-4 flex flex-col gap-2">
                            <button type="submit" className="sg-btn sg-btn-primary" disabled={processing}>
                                {page ? 'Save' : 'Create draft'}
                            </button>
                            {page && page.status !== 'published' && (
                                <button type="button" className="sg-btn sg-btn-secondary" onClick={() => action(`/staff/pages/${page.id}/publish`)}>
                                    Publish
                                </button>
                            )}
                            {page && page.status === 'published' && (
                                <button type="button" className="sg-btn sg-btn-secondary" onClick={() => action(`/staff/pages/${page.id}/unpublish`)}>
                                    Unpublish
                                </button>
                            )}
                        </div>
                    </div>

                    <label className="flex flex-col gap-1.5 border-t border-border pt-4 text-sm font-semibold text-body">
                        Visibility
                        <select value={data.visibility} onChange={(e) => setData('visibility', e.target.value)} className="form-input font-normal">
                            <option value="public">Public</option>
                            <option value="members">Members only</option>
                            <option value="paid">Paid members only</option>
                        </select>
                    </label>

                    <fieldset className="flex flex-col gap-3 border-t border-border pt-4">
                        <legend className="text-sm font-semibold text-body">SEO</legend>
                        {(
                            [
                                ['meta_title', 'Meta title'],
                                ['canonical_url', 'Canonical URL'],
                            ] as const
                        ).map(([key, label]) => (
                            <label key={key} className="block text-sm text-body">
                                {label}
                                <input className="form-input mt-1" value={data[key]} onChange={(e) => setData(key, e.target.value)} />
                            </label>
                        ))}
                        <label className="block text-sm text-body">
                            Meta description
                            <textarea className="form-input mt-1" rows={2} value={data.meta_description} onChange={(e) => setData('meta_description', e.target.value)} />
                        </label>
                    </fieldset>
                </aside>
            </form>

            {page && (
                <ConfirmDialog
                    open={confirmDelete}
                    title="Delete this page?"
                    description={`“${page.title}” will be taken off the site.`}
                    confirmLabel="Delete page"
                    destructive
                    onCancel={() => setConfirmDelete(false)}
                    onConfirm={() => {
                        setConfirmDelete(false);
                        router.delete(`/staff/pages/${page.id}`);
                    }}
                />
            )}
        </WorkspaceLayout>
    );
}
