import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MediaLibraryPicker from '../Components/Staff/MediaLibraryPicker';
import type { HeroMediaAsset } from '../Components/Staff/heroImageUpload';
import MediaIndex from '../Pages/Staff/Media/Index';
import { getInertiaMock, setMockPage } from '../test/inertia';
import type { SharedPageProps } from '../types/page';

function asset(id: number, overrides: Partial<HeroMediaAsset> = {}): HeroMediaAsset {
    return {
        id,
        url: `http://localhost/storage/media/${id}/original.jpg`,
        status: 'ready',
        failure_reason: null,
        width: 1200,
        height: 800,
        mime: 'image/jpeg',
        original_name: `photo-${id}`,
        alt: null,
        thumbnail_url: `http://localhost/storage/media/${id}/w640.jpg`,
        ...overrides,
    };
}

function page<T>(data: T[], overrides: Record<string, unknown> = {}) {
    return { data, current_page: 1, last_page: 1, per_page: 24, total: data.length, prev_page_url: null, next_page_url: null, ...overrides };
}

function jsonResponse(body: unknown) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(body) } as Response);
}

const staffPage: SharedPageProps = {
    appName: 'APES Newsroom',
    auth: {
        user: { id: 1, name: 'Sam Staff', email: 'sam@example.test', role: 'staff' },
        can: { accessStaff: true, accessAdmin: false },
    },
    errors: {},
};

describe('MediaLibraryPicker', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('opens a labelled dialog, searches, picks an image and returns focus', async () => {
        const fetchMock = vi.fn((url: string) =>
            jsonResponse(url.includes('q=otter') ? page([asset(2, { alt: 'Otter pup' })]) : page([asset(1), asset(2, { alt: 'Otter pup' })])),
        );
        vi.stubGlobal('fetch', fetchMock);
        const onPick = vi.fn();
        render(<MediaLibraryPicker onPick={onPick} />);

        const opener = screen.getByRole('button', { name: 'Choose from library' });
        fireEvent.click(opener);
        await act(async () => {});

        const dialog = screen.getByRole('dialog', { name: 'Media library' });
        expect(within(dialog).getByLabelText('Search uploaded images')).toHaveFocus();
        expect(within(dialog).getAllByRole('button', { name: /^Use / })).toHaveLength(2);

        fireEvent.change(within(dialog).getByLabelText('Search uploaded images'), { target: { value: 'otter' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Search' }));
        await act(async () => {});
        expect(fetchMock).toHaveBeenLastCalledWith('/staff/media/library?page=1&q=otter', expect.anything());

        fireEvent.click(within(dialog).getByRole('button', { name: 'Use Otter pup' }));
        expect(onPick).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(opener).toHaveFocus();
    });

    it('closes on Escape', async () => {
        vi.stubGlobal('fetch', vi.fn(() => jsonResponse(page([]))));
        render(<MediaLibraryPicker onPick={vi.fn()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Choose from library' }));
        await act(async () => {});
        expect(screen.getByText('No uploaded images found.')).toBeInTheDocument();

        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});

describe('Media library page', () => {
    beforeEach(() => {
        setMockPage(staffPage);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('lists the posts using an image before deleting it, and sends confirm only when needed', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                jsonResponse({
                    usage: [
                        { type: 'post', id: 9, title: 'Otter release day', status: 'published', as_hero: true, in_body: false, edit_url: '/staff/posts/9/edit' },
                    ],
                }),
            ),
        );
        const assets = page([{ ...asset(5, { original_name: 'otters' }), uploaded_by: 'Sam Staff', created_at: '2026-10-26T10:00:00Z', usage_count: 1 }]);
        render(<MediaIndex assets={assets} search="" />);

        expect(screen.getByText('Used by 1')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Delete otters' }));
        await act(async () => {});

        const dialog = screen.getByRole('alertdialog', { name: 'Delete otters?' });
        expect(within(dialog).getByRole('link', { name: /Post: Otter release day \(published, hero image\)/ })).toHaveAttribute(
            'href',
            '/staff/posts/9/edit',
        );
        expect(getInertiaMock().delete).not.toHaveBeenCalled();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Delete anyway' }));
        expect(getInertiaMock().delete).toHaveBeenCalledWith('/staff/media/5', expect.objectContaining({ data: { confirm: true } }));
    });

    it('can cancel a delete', async () => {
        vi.stubGlobal('fetch', vi.fn(() => jsonResponse({ usage: [] })));
        const assets = page([{ ...asset(6), uploaded_by: null, created_at: null, usage_count: 0 }]);
        render(<MediaIndex assets={assets} search="" />);

        fireEvent.click(screen.getByRole('button', { name: 'Delete photo-6' }));
        await act(async () => {});
        expect(screen.getByText('This image is not used by any post or page.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(getInertiaMock().delete).not.toHaveBeenCalled();
    });
});
