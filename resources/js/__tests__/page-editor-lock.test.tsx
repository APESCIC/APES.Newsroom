import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PagesEdit from '../Pages/Staff/Pages/Edit';
import { getInertiaMock, setMockPage } from '../test/inertia';

vi.mock('../Components/editor/EditorField', () => ({
    default: () => <div data-testid="editor" />,
}));

type PersistedPage = NonNullable<Parameters<typeof PagesEdit>[0]['page']>;

const publishedPage: PersistedPage = {
    id: 7,
    title: 'About us',
    slug: 'about-us',
    excerpt: null,
    content: { blocks: [] },
    status: 'published',
    hero_image: null,
    hero_image_alt: null,
    hero_image_caption: null,
    hero_image_credit: null,
    meta_title: null,
    meta_description: null,
    canonical_url: null,
    updated_at: '2026-08-10T09:00:00+00:00',
};

describe('page editor edit lock', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Editor', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
            errors: {},
        });
    });

    it('saves with the lock timestamp from an action reload such as Unpublish', () => {
        const { rerender } = render(<PagesEdit page={publishedPage} />);

        rerender(<PagesEdit page={{ ...publishedPage, status: 'draft', updated_at: '2026-08-10T09:05:00+00:00' }} />);
        fireEvent.submit(screen.getByLabelText('Title').closest('form') as HTMLFormElement);

        expect(getInertiaMock().patch).toHaveBeenCalledTimes(1);
        expect(getInertiaMock().patch.mock.calls[0][0]).toBe('/staff/pages/7');
        expect(getInertiaMock().patch.mock.calls[0][2]).toMatchObject({
            expected_updated_at: '2026-08-10T09:05:00+00:00',
        });
    });
});
