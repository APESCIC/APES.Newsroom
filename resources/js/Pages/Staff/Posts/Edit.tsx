import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useCallback, useEffect, useRef, useState } from 'react';
import type { OutputData } from '@editorjs/editorjs';
import EditorJsField from '../../../Components/editor/EditorJsField';
import AiAssistPanel from '../../../Components/Staff/AiAssistPanel';
import UnsplashPicker from '../../../Components/Staff/UnsplashPicker';

type Channel = { value: string; label: string };
type MailingListOption = { value: string; label: string };
type Revision = { id: number; title: string; editor: string | null; created_at: string | null };

type PostData = {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    content: OutputData;
    status: string;
    visibility?: string;
    channel: string;
    hero_image: string | null;
    hero_image_alt: string | null;
    hero_image_caption: string | null;
    hero_image_credit: string | null;
    meta_title: string | null;
    meta_description: string | null;
    canonical_url: string | null;
    scheduled_for: string | null;
    email_on_publish: boolean;
    mailing_lists: string[];
    newsletter_segment_id: number | null;
    review_notes: string | null;
    tags: string[];
    featured: boolean;
    co_author_ids: number[];
    updated_at: string | null;
};

const emptyContent: OutputData = {
    time: Date.now(),
    blocks: [{ type: 'paragraph', data: { text: '' } }],
    version: '2.29.0',
};

type PostForm = {
    title: string;
    slug: string;
    excerpt: string;
    content: OutputData;
    channel: string;
    visibility: string;
    hero_image: string;
    hero_image_alt: string;
    hero_image_caption: string;
    hero_image_credit: string;
    meta_title: string;
    meta_description: string;
    canonical_url: string;
    email_on_publish: boolean;
    mailing_lists: string[];
    newsletter_segment_id: string;
    tags_text: string;
    featured: boolean;
    co_author_ids: number[];
    expected_updated_at: string;
};

type FormErrors = Record<string, string>;

const INLINE_ERROR_FIELDS = [
    'conflict',
    'title',
    'slug',
    'channel',
    'visibility',
    'excerpt',
    'tags',
    'content',
    'hero_image',
    'hero_image_alt',
    'hero_image_caption',
    'hero_image_credit',
    'meta_title',
    'meta_description',
    'canonical_url',
    'email_on_publish',
    'mailing_lists',
    'newsletter_segment_id',
    'featured',
    'co_author_ids',
    'expected_updated_at',
] as const;

function formFromPost(post: PostData | null, channels: Channel[]): PostForm {
    return {
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        content: post?.content ?? emptyContent,
        channel: post?.channel ?? channels[0]?.value ?? 'apes_cic',
        visibility: post?.visibility ?? 'public',
        hero_image: post?.hero_image ?? '',
        hero_image_alt: post?.hero_image_alt ?? '',
        hero_image_caption: post?.hero_image_caption ?? '',
        hero_image_credit: post?.hero_image_credit ?? '',
        meta_title: post?.meta_title ?? '',
        meta_description: post?.meta_description ?? '',
        canonical_url: post?.canonical_url ?? '',
        email_on_publish: post?.email_on_publish ?? false,
        mailing_lists: post?.mailing_lists ?? [],
        newsletter_segment_id: post?.newsletter_segment_id ? String(post.newsletter_segment_id) : '',
        tags_text: (post?.tags ?? []).join(', '),
        featured: post?.featured ?? false,
        co_author_ids: post?.co_author_ids ?? [],
        expected_updated_at: post?.updated_at ?? '',
    };
}

function parseTags(value: string): string[] {
    return value
        .split(',')
        .map((tag) => tag.trim())
        .filter(Boolean);
}

function reconcileSavedForm(current: PostForm, submitted: PostForm, authoritative: PostForm): PostForm {
    return Object.fromEntries(
        Object.entries(authoritative).map(([key, value]) => {
            const field = key as keyof PostForm;
            const changedSinceSubmit = JSON.stringify(current[field]) !== JSON.stringify(submitted[field]);

            return [field, field === 'expected_updated_at' || !changedSinceSubmit ? value : current[field]];
        }),
    ) as PostForm;
}

function mergeErrors(pageErrors: FormErrors | undefined, formErrors: FormErrors): FormErrors {
    return { ...(pageErrors ?? {}), ...formErrors };
}

function fieldError(errors: FormErrors, field: string): string | undefined {
    if (errors[field]) {
        return errors[field];
    }

    const nested = Object.entries(errors).find(([key]) => key.startsWith(`${field}.`));

    return nested?.[1];
}

function leftoverErrors(errors: FormErrors): Array<[string, string]> {
    return Object.entries(errors).filter(([key]) => {
        return !INLINE_ERROR_FIELDS.some((field) => key === field || key.startsWith(`${field}.`));
    });
}

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p className="text-sm text-red-600" role="alert">
            {message}
        </p>
    );
}

export default function PostEdit({
    post,
    channels,
    mailingLists,
    segments = [],
    staffUsers = [],
    canPublish,
    revisions,
}: {
    post: PostData | null;
    channels: Channel[];
    mailingLists: MailingListOption[];
    segments?: Array<{ id: number; name: string; newsletter: string; also: string }>;
    staffUsers?: Array<{ id: number; name: string; email: string }>;
    canPublish: boolean;
    revisions: Revision[];
}) {
    const isNew = post === null;
    const page = usePage();
    const pageErrors = (page.props as { errors?: FormErrors }).errors;
    const [scheduleAt, setScheduleAt] = useState(post?.scheduled_for ?? '');
    const [rejectNotes, setRejectNotes] = useState('');
    const autosaveTimer = useRef<number | null>(null);
    const previousPostId = useRef<number | null>(post?.id ?? null);

    const {
        data,
        setData,
        post: submitPost,
        patch,
        processing,
        transform,
        setDefaults,
        isDirty,
        errors: formErrors,
    } = useForm<PostForm>(formFromPost(post, channels));

    const errors = mergeErrors(pageErrors, formErrors as FormErrors);
    const hasErrors = Object.keys(errors).length > 0;
    const extraErrors = leftoverErrors(errors);

    transform(({ tags_text: tagsText, ...form }) => ({ ...form, tags: parseTags(tagsText) }));

    useEffect(() => {
        const nextPostId = post?.id ?? null;
        if (nextPostId === null || previousPostId.current === nextPostId) {
            return;
        }

        previousPostId.current = nextPostId;
        const authoritativeForm = formFromPost(post, channels);
        setData(authoritativeForm);
        setDefaults(authoritativeForm);
        setScheduleAt(post?.scheduled_for ?? '');
    }, [channels, post, setData, setDefaults]);

    const saveExisting = useCallback(
        (submittedData: PostForm) => {
            if (!post) {
                return;
            }

            patch(`/staff/posts/${post.id}`, {
                preserveScroll: true,
                onSuccess: (successPage) => {
                    const savedPost = (successPage.props as { post?: PostData }).post;
                    if (!savedPost?.updated_at) {
                        return;
                    }

                    const authoritativeForm = formFromPost(savedPost, channels);
                    setDefaults(authoritativeForm);
                    setData((current) => reconcileSavedForm(current, submittedData, authoritativeForm));
                },
            });
        },
        [channels, patch, post, setData, setDefaults],
    );

    useEffect(() => {
        if (isNew || !post || !isDirty || processing || hasErrors) {
            return;
        }

        if (autosaveTimer.current) {
            window.clearTimeout(autosaveTimer.current);
        }

        autosaveTimer.current = window.setTimeout(() => {
            saveExisting(data);
        }, 8000);

        return () => {
            if (autosaveTimer.current) {
                window.clearTimeout(autosaveTimer.current);
            }
        };
    }, [data, hasErrors, isDirty, isNew, post, processing, saveExisting]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (isNew) {
            submitPost('/staff/posts');
        } else {
            saveExisting(data);
        }
    };

    const toggleList = (value: string) => {
        setData(
            'mailing_lists',
            data.mailing_lists.includes(value)
                ? data.mailing_lists.filter((list) => list !== value)
                : [...data.mailing_lists, value],
        );
    };

    const action = (url: string, body?: Record<string, string>) => {
        router.post(url, body ?? {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title={isNew ? 'New post' : `Edit: ${post.title}`} />
            <main className="mx-auto max-w-3xl px-6 py-12">
                <Link href="/staff/posts" className="text-sm text-teal-deep hover:underline">
                    ← Posts
                </Link>
                <h1 className="mt-4 text-2xl font-semibold">{isNew ? 'New draft' : 'Edit draft'}</h1>
                {!isNew && (
                    <p className="mt-1 text-sm text-muted">
                        Status: {post.status}{' '}
                        <Link href={`/staff/posts/${post.id}/preview`} className="underline">
                            Preview
                        </Link>
                        {' · '}
                        <Link href={`/staff/posts/${post.id}/campaign`} className="underline">
                            Campaign preview
                        </Link>
                    </p>
                )}
                {post?.review_notes && (
                    <p className="mt-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Review notes: {post.review_notes}
                    </p>
                )}
                {errors.conflict && (
                    <p className="mt-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                        {errors.conflict}
                    </p>
                )}
                {extraErrors.map(([key, message]) => (
                    <p
                        key={key}
                        className="mt-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800"
                        role="alert"
                    >
                        {message}
                    </p>
                ))}

                <form onSubmit={submit} className="mt-8 flex flex-col gap-4">
                    <div>
                        <label htmlFor="title">Title</label>
                        <input
                            id="title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            required
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.title} />
                    </div>
                    <div>
                        <label htmlFor="slug">Slug</label>
                        <input
                            id="slug"
                            value={data.slug}
                            onChange={(e) => setData('slug', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.slug} />
                    </div>
                    <div>
                        <label htmlFor="channel">Channel</label>
                        <select
                            id="channel"
                            value={data.channel}
                            onChange={(e) => setData('channel', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        >
                            {channels.map((channel) => (
                                <option key={channel.value} value={channel.value}>
                                    {channel.label}
                                </option>
                            ))}
                        </select>
                        <FieldError message={errors.channel} />
                    </div>
                    <div>
                        <label htmlFor="visibility">Visibility</label>
                        <select
                            id="visibility"
                            value={data.visibility}
                            onChange={(e) => setData('visibility', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        >
                            <option value="public">Public</option>
                            <option value="members">Members only</option>
                            <option value="paid">Paid members only</option>
                        </select>
                        <FieldError message={errors.visibility} />
                    </div>
                    <div className="flex items-center gap-2">
                        <input
                            id="featured"
                            type="checkbox"
                            checked={data.featured}
                            onChange={(e) => setData('featured', e.target.checked)}
                        />
                        <label htmlFor="featured">Featured on homepage / channel</label>
                        <FieldError message={errors.featured} />
                    </div>
                    {staffUsers.length > 0 && (
                        <div>
                            <label htmlFor="co_authors">Co-authors</label>
                            <select
                                id="co_authors"
                                multiple
                                value={data.co_author_ids.map(String)}
                                onChange={(e) => {
                                    const selected = Array.from(e.target.selectedOptions).map((opt) => Number(opt.value));
                                    setData('co_author_ids', selected);
                                }}
                                className="w-full rounded border px-3 py-2"
                                size={Math.min(6, Math.max(3, staffUsers.length))}
                            >
                                {staffUsers.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name} ({user.email})
                                    </option>
                                ))}
                            </select>
                            <FieldError message={fieldError(errors, 'co_author_ids')} />
                        </div>
                    )}
                    <div>
                        <label htmlFor="excerpt">Excerpt</label>
                        <textarea
                            id="excerpt"
                            value={data.excerpt}
                            onChange={(e) => setData('excerpt', e.target.value)}
                            rows={2}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.excerpt} />
                    </div>
                    <div>
                        <label htmlFor="tags">Tags (comma-separated)</label>
                        <input
                            id="tags"
                            value={data.tags_text}
                            onChange={(e) => setData('tags_text', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={fieldError(errors, 'tags')} />
                    </div>
                    <div>
                        <label htmlFor="body">Body</label>
                        <EditorJsField
                            initialData={data.content}
                            onChange={(content) => setData('content', content)}
                        />
                        <FieldError message={errors.content} />
                        <div className="mt-3">
                            <AiAssistPanel
                                title={data.title}
                                excerpt={data.excerpt}
                                onInsert={(text) => {
                                    setData('content', {
                                        ...data.content,
                                        time: Date.now(),
                                        blocks: [
                                            ...(data.content.blocks ?? []),
                                            { type: 'paragraph', data: { text } },
                                        ],
                                    });
                                }}
                            />
                        </div>
                    </div>

                    <fieldset className="flex flex-col gap-3 border-t pt-4">
                        <legend className="font-medium">Hero image</legend>
                        <input
                            placeholder="Image URL"
                            value={data.hero_image}
                            onChange={(e) => setData('hero_image', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.hero_image} />
                        <input
                            placeholder="Alt text"
                            value={data.hero_image_alt}
                            onChange={(e) => setData('hero_image_alt', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.hero_image_alt} />
                        <input
                            placeholder="Caption"
                            value={data.hero_image_caption}
                            onChange={(e) => setData('hero_image_caption', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.hero_image_caption} />
                        <input
                            placeholder="Credit"
                            value={data.hero_image_credit}
                            onChange={(e) => setData('hero_image_credit', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.hero_image_credit} />
                        <UnsplashPicker
                            onSelect={({ url, credit, alt }) => {
                                setData((current) => ({
                                    ...current,
                                    hero_image: url,
                                    hero_image_credit: credit,
                                    hero_image_alt: alt || current.hero_image_alt,
                                }));
                            }}
                        />
                    </fieldset>

                    <fieldset className="flex flex-col gap-3 border-t pt-4">
                        <legend className="font-medium">SEO</legend>
                        <input
                            placeholder="Meta title"
                            value={data.meta_title}
                            onChange={(e) => setData('meta_title', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.meta_title} />
                        <textarea
                            placeholder="Meta description"
                            value={data.meta_description}
                            onChange={(e) => setData('meta_description', e.target.value)}
                            rows={2}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.meta_description} />
                        <input
                            placeholder="Canonical URL"
                            value={data.canonical_url}
                            onChange={(e) => setData('canonical_url', e.target.value)}
                            className="w-full rounded border px-3 py-2"
                        />
                        <FieldError message={errors.canonical_url} />
                    </fieldset>

                    <fieldset className="flex flex-col gap-3 border-t pt-4">
                        <legend className="font-medium">Email campaign</legend>
                        <label className="flex gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.email_on_publish}
                                onChange={(e) => setData('email_on_publish', e.target.checked)}
                            />
                            Email this post on publish
                        </label>
                        <FieldError message={errors.email_on_publish} />
                        {data.email_on_publish && (
                            <div className="flex flex-col gap-2 pl-6">
                                {mailingLists.map((list) => (
                                    <label key={list.value} className="flex gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={data.mailing_lists.includes(list.value)}
                                            onChange={() => toggleList(list.value)}
                                        />
                                        {list.label}
                                    </label>
                                ))}
                                <label className="text-sm" htmlFor="newsletter_segment_id">
                                    Segment (also confirmed on another newsletter)
                                    <select
                                        id="newsletter_segment_id"
                                        className="mt-1 w-full rounded border px-2 py-1"
                                        value={data.newsletter_segment_id}
                                        onChange={(e) => setData('newsletter_segment_id', e.target.value)}
                                    >
                                        <option value="">No segment</option>
                                        {segments.map((segment) => (
                                            <option key={segment.id} value={String(segment.id)}>
                                                {segment.name} ({segment.newsletter} and {segment.also})
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                        )}
                        <FieldError message={fieldError(errors, 'mailing_lists')} />
                    </fieldset>
                    <FieldError message={errors.expected_updated_at} />

                    <div className="flex flex-wrap gap-2 border-t pt-4">
                        <button type="submit" disabled={processing} className="rounded bg-apes-primary px-4 py-2 text-white">
                            {isNew ? 'Create draft' : 'Save'}
                        </button>
                        {!isNew && (
                            <button
                                type="button"
                                className="rounded border px-4 py-2"
                                onClick={() => action(`/staff/posts/${post.id}/submit`)}
                            >
                                Submit for review
                            </button>
                        )}
                        {!isNew && canPublish && (
                            <>
                                <button
                                    type="button"
                                    className="rounded border px-4 py-2"
                                    onClick={() => action(`/staff/posts/${post.id}/publish`)}
                                >
                                    Publish
                                </button>
                                <button
                                    type="button"
                                    className="rounded border px-4 py-2"
                                    onClick={() => action(`/staff/posts/${post.id}/unpublish`)}
                                >
                                    Unpublish
                                </button>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="datetime-local"
                                        value={scheduleAt}
                                        onChange={(e) => setScheduleAt(e.target.value)}
                                        className="rounded border px-2 py-1 text-sm"
                                    />
                                    <button
                                        type="button"
                                        className="rounded border px-4 py-2"
                                        onClick={() =>
                                            action(`/staff/posts/${post.id}/schedule`, {
                                                scheduled_for: scheduleAt,
                                            })
                                        }
                                    >
                                        Schedule
                                    </button>
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        value={rejectNotes}
                                        onChange={(e) => setRejectNotes(e.target.value)}
                                        placeholder="Rejection notes"
                                        className="rounded border px-2 py-1 text-sm"
                                    />
                                    <button
                                        type="button"
                                        className="rounded border px-4 py-2"
                                        onClick={() =>
                                            action(`/staff/posts/${post.id}/reject`, {
                                                review_notes: rejectNotes,
                                            })
                                        }
                                    >
                                        Reject
                                    </button>
                                </div>
                            </>
                        )}
                        {!isNew && (
                            <button
                                type="button"
                                className="rounded border border-red-300 px-4 py-2 text-red-700"
                                onClick={() => router.delete(`/staff/posts/${post.id}`)}
                            >
                                Soft delete
                            </button>
                        )}
                    </div>
                </form>

                {!isNew && revisions.length > 0 && (
                    <section className="mt-10 border-t pt-6">
                        <h2 className="text-lg font-medium">Revisions</h2>
                        <ul className="mt-3 space-y-2 text-sm">
                            {revisions.map((revision) => (
                                <li key={revision.id} className="flex items-center justify-between gap-4">
                                    <span>
                                        {revision.title} — {revision.editor ?? 'Unknown'} —{' '}
                                        {revision.created_at
                                            ? new Date(revision.created_at).toLocaleString('en-GB')
                                            : '—'}
                                    </span>
                                    <button
                                        type="button"
                                        className="underline"
                                        onClick={() =>
                                            router.post(`/staff/posts/${post.id}/revisions/${revision.id}/restore`)
                                        }
                                    >
                                        Restore
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </main>
        </>
    );
}
