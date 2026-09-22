import { act, fireEvent, render, screen } from '@testing-library/react';
import type { OutputData } from '@editorjs/editorjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PostEdit from '../Pages/Staff/Posts/Edit';
import { getInertiaMock, setMockPage } from '../test/inertia';

vi.mock('../Components/editor/EditorJsField', () => ({
    default: ({ initialData, onChange }: { initialData: OutputData; onChange: (data: OutputData) => void }) => (
        <>
            <output data-testid="editor-content">{JSON.stringify(initialData)}</output>
            <button type="button" onClick={() => onChange({ blocks: [{ type: 'paragraph', data: { text: 'Changed' } }] })}>
                Change body
            </button>
        </>
    ),
}));

const content: OutputData = { blocks: [{ type: 'paragraph', data: { text: 'Original' } }] };

type PersistedPost = NonNullable<Parameters<typeof PostEdit>[0]['post']>;

const persistedPost: PersistedPost = {
    id: 12,
    title: 'A saved draft',
    slug: 'a-saved-draft',
    excerpt: 'Draft excerpt',
    content,
    status: 'draft',
    channel: 'apes_cic',
    hero_image: null,
    hero_image_alt: null,
    hero_image_caption: null,
    hero_image_credit: null,
    meta_title: null,
    meta_description: null,
    canonical_url: null,
    scheduled_for: null,
    email_on_publish: false,
    mailing_lists: [],
    review_notes: null,
    tags: ['rescue'],
    featured: false,
    co_author_ids: [],
    updated_at: '2026-08-10T09:00:00+00:00',
};

const props = {
    channels: [{ value: 'apes_cic', label: 'APES CIC' }],
    mailingLists: [],
    canPublish: true,
    revisions: [],
};

describe('post editor autosave', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Editor', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
            errors: {},
        });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('does not autosave an unchanged persisted post', () => {
        render(<PostEdit post={persistedPost} {...props} />);

        act(() => vi.advanceTimersByTime(8_000));

        expect(getInertiaMock().patch).not.toHaveBeenCalled();
    });

    it('rehydrates server-generated fields after the create-to-edit redirect', () => {
        const { rerender } = render(<PostEdit post={null} {...props} />);
        expect(screen.getByLabelText('Slug')).toHaveValue('');

        rerender(<PostEdit post={persistedPost} {...props} />);

        expect(screen.getByLabelText('Slug')).toHaveValue('a-saved-draft');
        expect(screen.getByLabelText('Tags (comma-separated)')).toHaveValue('rescue');
        expect(screen.getByRole('link', { name: 'Preview' })).toHaveAttribute('href', '/staff/posts/12/preview');

        act(() => vi.advanceTimersByTime(8_000));
        expect(getInertiaMock().patch).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Excerpt'), { target: { value: 'Hydrated lock check' } });
        act(() => vi.advanceTimersByTime(8_000));

        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);
        expect(getInertiaMock().patch.mock.calls[0][0]).toBe('/staff/posts/12');
        expect(getInertiaMock().patch.mock.calls[0][2]).toMatchObject({
            excerpt: 'Hydrated lock check',
            expected_updated_at: '2026-08-10T09:00:00+00:00',
        });
    });

    it('autosaves a tag-only edit once and uses the returned lock timestamp on the next save', () => {
        render(<PostEdit post={persistedPost} {...props} />);

        act(() => vi.advanceTimersByTime(8_000));
        getInertiaMock().patch.mockClear();

        fireEvent.change(screen.getByLabelText('Tags (comma-separated)'), { target: { value: 'rescue, clinic' } });
        act(() => vi.advanceTimersByTime(8_000));

        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);
        expect(getInertiaMock().patch.mock.calls[0][2]).toMatchObject({
            tags: ['rescue', 'clinic'],
            expected_updated_at: '2026-08-10T09:00:00+00:00',
        });
        expect(getInertiaMock().patch.mock.calls[0][2]).not.toHaveProperty('tags_text');
        const options = getInertiaMock().patch.mock.calls[0][1] as {
            onSuccess?: (page: { props: { post: typeof persistedPost } }) => void;
        };
        act(() => options.onSuccess?.({
            props: { post: { ...persistedPost, tags: ['rescue', 'clinic'], updated_at: '2026-08-10T09:01:00+00:00' } },
        }));
        act(() => vi.advanceTimersByTime(8_000));

        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);

        fireEvent.change(screen.getByLabelText('Excerpt'), { target: { value: 'A later edit' } });
        act(() => vi.advanceTimersByTime(8_000));

        expect(getInertiaMock().patch).toHaveBeenCalledTimes(2);
        expect(getInertiaMock().patch.mock.calls[1][2]).toMatchObject({
            excerpt: 'A later edit',
            expected_updated_at: '2026-08-10T09:01:00+00:00',
        });
    });

    it('rehydrates every persisted field from the successful same-id save response', () => {
        const normalizedContent: OutputData = {
            blocks: [{ type: 'paragraph', data: { text: 'Server-normalized body' } }],
        };
        render(<PostEdit
            post={persistedPost}
            {...props}
            channels={[
                ...props.channels,
                { value: 'wildlife', label: 'Wildlife' },
            ]}
            mailingLists={[{ value: 'daily', label: 'Daily briefing' }]}
        />);

        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Locally edited title' } });
        fireEvent.change(screen.getByLabelText('Slug'), { target: { value: 'local-slug' } });
        fireEvent.change(screen.getByLabelText('Excerpt'), { target: { value: 'Local excerpt' } });
        act(() => vi.advanceTimersByTime(8_000));

        const options = getInertiaMock().patch.mock.calls[0][1] as {
            onSuccess?: (page: { props: { post: typeof persistedPost } }) => void;
        };
        act(() => options.onSuccess?.({
            props: {
                post: {
                    ...persistedPost,
                    title: 'Server-normalized title',
                    slug: 'server-normalized-slug',
                    excerpt: 'Server-normalized excerpt',
                    content: normalizedContent,
                    channel: 'wildlife',
                    hero_image: 'https://cdn.example.test/normalized.jpg',
                    hero_image_alt: 'Server-normalized alt text',
                    hero_image_caption: 'Server-normalized caption',
                    hero_image_credit: 'Server-normalized credit',
                    meta_title: 'Server-normalized meta title',
                    meta_description: 'Server-normalized meta description',
                    canonical_url: 'https://www.apesnews.org.uk/articles/server-normalized',
                    email_on_publish: true,
                    mailing_lists: ['daily'],
                    tags: ['server', 'normalized'],
                    updated_at: '2026-08-10T09:01:00+00:00',
                },
            },
        }));

        expect(screen.getByLabelText('Title')).toHaveValue('Server-normalized title');
        expect(screen.getByLabelText('Slug')).toHaveValue('server-normalized-slug');
        expect(screen.getByLabelText('Excerpt')).toHaveValue('Server-normalized excerpt');
        expect(screen.getByTestId('editor-content')).toHaveTextContent(JSON.stringify(normalizedContent));
        expect(screen.getByLabelText('Channel')).toHaveValue('wildlife');
        expect(screen.getByPlaceholderText('Image URL')).toHaveValue('https://cdn.example.test/normalized.jpg');
        expect(screen.getByPlaceholderText('Alt text')).toHaveValue('Server-normalized alt text');
        expect(screen.getByPlaceholderText('Caption')).toHaveValue('Server-normalized caption');
        expect(screen.getByPlaceholderText('Credit')).toHaveValue('Server-normalized credit');
        expect(screen.getByPlaceholderText('Meta title')).toHaveValue('Server-normalized meta title');
        expect(screen.getByPlaceholderText('Meta description')).toHaveValue('Server-normalized meta description');
        expect(screen.getByPlaceholderText('Canonical URL')).toHaveValue('https://www.apesnews.org.uk/articles/server-normalized');
        expect(screen.getByLabelText('Email this post on publish')).toBeChecked();
        expect(screen.getByLabelText('Daily briefing')).toBeChecked();
        expect(screen.getByLabelText('Tags (comma-separated)')).toHaveValue('server, normalized');

        act(() => vi.advanceTimersByTime(8_000));
        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);
    });

    it('preserves edits made during a save and autosaves them exactly once afterward', () => {
        render(<PostEdit post={persistedPost} {...props} />);

        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Submitted title' } });
        act(() => vi.advanceTimersByTime(8_000));
        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);
        expect(getInertiaMock().visits[0]?.status).toBe('pending');

        const firstOptions = getInertiaMock().patch.mock.calls[0][1] as {
            onSuccess?: (page: { props: { post: typeof persistedPost } }) => void;
        };
        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Newer local title' } });
        act(() => vi.advanceTimersByTime(8_000));
        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);

        act(() => firstOptions.onSuccess?.({
            props: {
                post: {
                    ...persistedPost,
                    title: 'Server-normalized submitted title',
                    updated_at: '2026-08-10T09:01:00+00:00',
                },
            },
        }));

        expect(screen.getByLabelText('Title')).toHaveValue('Newer local title');

        act(() => vi.advanceTimersByTime(8_000));
        expect(getInertiaMock().patch).toHaveBeenCalledTimes(2);
        expect(getInertiaMock().patch.mock.calls[1][2]).toMatchObject({
            title: 'Newer local title',
            expected_updated_at: '2026-08-10T09:01:00+00:00',
        });

        const secondOptions = getInertiaMock().patch.mock.calls[1][1] as {
            onSuccess?: (page: { props: { post: typeof persistedPost } }) => void;
        };
        act(() => secondOptions.onSuccess?.({
            props: {
                post: {
                    ...persistedPost,
                    title: 'Server-normalized newer title',
                    updated_at: '2026-08-10T09:02:00+00:00',
                },
            },
        }));

        expect(screen.getByLabelText('Title')).toHaveValue('Server-normalized newer title');
        act(() => vi.advanceTimersByTime(8_000));
        expect(getInertiaMock().patch).toHaveBeenCalledTimes(2);
    });

    it('renders server errors and stops autosave retries after a conflict', () => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Editor', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
            errors: {
                slug: 'This slug is already in use.',
                conflict: 'This post was edited elsewhere.',
            },
        });

        render(<PostEdit post={persistedPost} {...props} />);

        expect(screen.getByText('This slug is already in use.')).toBeInTheDocument();
        expect(screen.getByText('This post was edited elsewhere.')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Do not retry this edit' } });
        act(() => vi.advanceTimersByTime(16_000));

        expect(getInertiaMock().patch).not.toHaveBeenCalled();
    });

    it('surfaces failed-request field errors and does not retry while they remain', () => {
        render(<PostEdit post={persistedPost} {...props} />);

        fireEvent.change(screen.getByPlaceholderText('Canonical URL'), { target: { value: 'not-a-url' } });
        act(() => vi.advanceTimersByTime(8_000));
        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);
        expect(getInertiaMock().visits[0]?.status).toBe('pending');

        const options = getInertiaMock().patch.mock.calls[0][1] as {
            onError?: (errors: Record<string, string>) => void;
        };
        act(() => options.onError?.({
            canonical_url: 'The canonical url field must be a valid URL.',
            excerpt: 'The excerpt field must not be greater than 1000 characters.',
            status: 'This draft cannot be saved in its current state.',
        }));
        expect(getInertiaMock().visits[0]?.status).toBe('error');

        expect(screen.getByText('The canonical url field must be a valid URL.')).toBeInTheDocument();
        expect(screen.getByText('The excerpt field must not be greater than 1000 characters.')).toBeInTheDocument();
        expect(screen.getByText('This draft cannot be saved in its current state.')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Still blocked by visible errors' } });
        act(() => vi.advanceTimersByTime(16_000));
        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);
    });
});
