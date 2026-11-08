import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { templateUsageLabel } from '../Components/Staff/NewsletterTemplateUsage';
import NewsletterTemplateEdit, { placeholderProblems, type TemplateDraft } from '../Pages/Staff/NewsletterTemplates/Edit';
import { getInertiaMock, setMockPage } from '../test/inertia';

const placeholders = ['post_title', 'post_excerpt', 'post_author', 'read_more_url', 'recipient_first_name', 'newsletter_name', 'channel_label', 'site_name'];
const postPlaceholders = placeholders.filter((tag) => tag !== 'recipient_first_name');

const defaults: TemplateDraft = {
    subject: '{{ post_title }}',
    intro: '',
    settings: {
        sign_off: 'Thanks,  \n{{ site_name }}',
        read_more_label: 'Read the full story',
        members_cta: 'This article is for members. Create a free account to read the full post.',
        paid_cta: 'This article is for paid members. Subscribe in your account to read the full post.',
        show_hero: true,
        show_excerpt: false,
        show_full_post: true,
    },
};

function renderPage(overrides: Partial<Parameters<typeof NewsletterTemplateEdit>[0]> = {}) {
    return render(
        <NewsletterTemplateEdit
            target="default"
            newsletter={null}
            template={{ ...defaults, is_overridden: false, updated_at: null }}
            defaults={defaults}
            placeholders={placeholders}
            postPlaceholders={postPlaceholders}
            samplePlaceholders={{}}
            versions={[]}
            testRecipient="staff@example.test"
            previewPosts={[{ id: 7, title: 'Dragons rehomed', visibility: 'public' }]}
            {...overrides}
        />,
    );
}

describe('Staff newsletter template editor', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: { id: 2, name: 'Sam Staff', email: 'staff@example.test', role: 'staff' }, can: { accessStaff: true, accessAdmin: false } },
        });
    });

    it('flags unknown placeholders and disables saving', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.type(screen.getByLabelText('Introduction (Markdown, optional)'), 'Hi {{{{ password }}');

        expect(screen.getByText('Unknown placeholder {{ password }} in the introduction.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save template' })).toBeDisabled();
    });

    it('inserts a placeholder into the focused field and saves the whole draft', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByLabelText('Introduction (Markdown, optional)'));
        await user.click(screen.getByRole('button', { name: '{{ recipient_first_name }}' }));
        await user.click(screen.getByRole('button', { name: 'Save template' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/staff/newsletter-templates/default');
        expect(visit?.data).toMatchObject({ intro: '{{ recipient_first_name }}', settings: { read_more_label: 'Read the full story' } });
    });

    it('does not offer the reader’s first name in campaign-wide wording', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByLabelText('Members-only wording'));

        expect(screen.getByRole('button', { name: '{{ recipient_first_name }}' })).toBeDisabled();
        expect(screen.getByRole('button', { name: '{{ post_title }}' })).toBeEnabled();
    });

    it('turns off the excerpt switch when the full post is hidden', () => {
        renderPage();

        fireEvent.click(screen.getByLabelText('Full post (public posts; otherwise the excerpt is sent)'));

        expect(screen.getByLabelText('Excerpt above the full post')).toBeDisabled();
    });

    it('offers revert only for a saved template and confirms first', async () => {
        const user = userEvent.setup();
        renderPage({ newsletter: { id: 4, name: 'MyAPES' }, target: '4', template: { ...defaults, is_overridden: true, updated_at: null } });

        await user.click(screen.getByRole('button', { name: 'Revert to the default template' }));
        await user.click(screen.getByRole('button', { name: 'Revert' }));

        expect(getInertiaMock().post).toHaveBeenCalledWith('/staff/newsletter-templates/4/revert', {}, expect.anything());
    });

    it('shows the preview controls for each visibility', () => {
        renderPage();

        expect(screen.getByLabelText('Post')).toHaveValue('7');
        for (const label of ['Public', 'Members', 'Paid']) {
            expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
        }
        expect(screen.getByRole('button', { name: 'Public' })).toHaveAttribute('aria-pressed', 'true');
    });
});

describe('placeholderProblems', () => {
    it('matches the server rules for each field', () => {
        const draft: TemplateDraft = {
            ...defaults,
            subject: '{{ secret }}',
            settings: { ...defaults.settings, paid_cta: 'Hi {{ recipient_first_name }}' },
        };

        expect(placeholderProblems(draft, placeholders, postPlaceholders)).toEqual([
            'Unknown placeholder {{ secret }} in the subject.',
            "{{ recipient_first_name }} can't be used in the paid wording.",
        ]);
        expect(placeholderProblems(defaults, placeholders, postPlaceholders)).toEqual([]);
    });
});

describe('templateUsageLabel', () => {
    it('names each template state', () => {
        expect(templateUsageLabel('own')).toBe('Own template');
        expect(templateUsageLabel('default_edited')).toBe('Default template (edited)');
        expect(templateUsageLabel('built_in')).toBe('Default template');
    });
});
