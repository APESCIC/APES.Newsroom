import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => <title>{title}</title>,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { post: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        const data = { ...initial };
        return {
            data,
            setData: (key: string, value: unknown) => {
                (data as Record<string, unknown>)[key] = value;
            },
            post: vi.fn(),
            processing: false,
            errors: {},
        };
    },
    usePage: () => ({
        props: {
            auth: { user: { name: 'Admin' }, can: { accessStaff: true, accessAdmin: true } },
            flash: {},
            ziggy: { location: 'http://apes-newsroom.test' },
        },
    }),
}));

vi.mock('../Components/Layout/WorkspaceLayout', () => ({
    default: ({ children, title }: { children: React.ReactNode; title: string }) => (
        <div>
            <h1>{title}</h1>
            {children}
        </div>
    ),
}));

import Wizard from '../Pages/Staff/Posts/Publish/Wizard';

const baseProps = {
    post: { id: 9, title: 'Wizard post', slug: 'wizard-post', status: 'draft', edit_url: '/staff/posts/9/edit' },
    steps: [
        { id: 'audience', label: 'Audience', number: 1 },
        { id: 'when', label: 'When', number: 2 },
        { id: 'social', label: 'Social', number: 3, disabled: true, note: 'Coming in v1.34.0' },
        { id: 'confirm', label: 'Confirm', number: 4 },
        { id: 'result', label: 'Result', number: 5 },
    ],
    draft: {
        visibility: 'public',
        mailing_lists: ['apes_cic'],
        newsletter_segment_id: null,
        newsletter_template: null,
        email_mode: 'now',
        website_mode: 'now',
        website_at: null,
        email_at: null,
        step: 'audience',
    },
    targets: [
        {
            id: 1,
            channel: 'website',
            channel_label: 'Website',
            status: 'pending',
            status_label: 'Pending',
            scheduled_for: null,
            result_message: null,
            campaign_id: null,
        },
    ],
    recipientCounts: { total: 3, included: 3, excluded: 0 },
    visibilityOptions: [
        { value: 'public', label: 'Public' },
        { value: 'members', label: 'Members only' },
        { value: 'paid', label: 'Paid members only' },
    ],
    mailingListOptions: [{ value: 'apes_cic', label: 'APES CIC' }],
    segmentOptions: [],
    templateOptions: [],
    sender: { verified: true, message: null, identity: { address: 'news@example.com', name: 'News' } },
    shareUrl: 'https://www.apesnews.org.uk/articles/wizard-post',
    timezone: 'Europe/London',
};

describe('Publish wizard', () => {
    it('marks the current step with aria-current', () => {
        render(<Wizard {...baseProps} step="audience" />);
        const current = screen.getByRole('link', { name: /1\. Audience/i });
        expect(current).toHaveAttribute('aria-current', 'step');
    });

    it('shows recipient counts on the audience step', () => {
        render(<Wizard {...baseProps} step="audience" />);
        expect(screen.getByTestId('recipient-count')).toHaveTextContent('3');
    });

    it('shows share buttons on the result step', () => {
        render(<Wizard {...baseProps} step="result" />);
        expect(screen.getByText('X')).toBeInTheDocument();
        expect(screen.getByText('Copy link')).toBeInTheDocument();
        expect(screen.getByText('https://www.apesnews.org.uk/articles/wizard-post')).toBeInTheDocument();
    });
});
