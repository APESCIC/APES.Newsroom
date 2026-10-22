import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import WebhooksIndex from '../Pages/Admin/Webhooks/Index';
import { setMockPage } from '../test/inertia';

const endpoint = { id: 3, name: 'Partner', url: 'https://hooks.example.com', events: ['post.published'], is_active: true, secret_hint: 'whsec_…abcd' };

describe('Admin webhooks page', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' }, can: { accessStaff: true, accessAdmin: true } },
        });
    });

    it('shows only the secret hint normally', () => {
        render(<WebhooksIndex endpoints={[endpoint]} availableEvents={['post.published']} />);

        expect(screen.getByText('Secret whsec_…abcd')).toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Signing secret' })).not.toBeInTheDocument();
    });

    it('reveals the full secret right after it is created or rotated', () => {
        render(
            <WebhooksIndex
                endpoints={[endpoint]}
                availableEvents={['post.published']}
                revealedSecret={{ id: 3, name: 'Partner', secret: 'whsec_full-secret-abcd' }}
            />,
        );

        expect(within(screen.getByRole('region', { name: 'Signing secret' })).getByText('whsec_full-secret-abcd')).toBeInTheDocument();
    });
});
