import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import ChangeLogHubIndex, { type PublicRelease } from '../Pages/ChangeLogHub/Index';
import { setMockPage } from '../test/inertia';

function release(overrides: Partial<PublicRelease> = {}): PublicRelease {
    return {
        id: 1,
        version: 'v1.1.1',
        previous_version: 'v1.1.0',
        released_at: '2026-09-18',
        channel: 'stable',
        channel_label: 'Stable',
        version_type: 'patch stable',
        theme: 'Change Log Hub',
        is_current: true,
        slug: 'release-v111',
        change_types: ['added'],
        topic_tags: ['public-facing'],
        tags: ['added', 'public-facing', 'stable', 'current'],
        summary: 'Added the Change Log Hub.',
        detailed_changes: ['Shipped /change-log-hub'],
        affected_areas: ['Website: APES Newsroom'],
        version_decision: ['New version: v1.1.1'],
        validation: ['Checks run: tests'],
        search_text: 'v1.1.1 change log hub added public-facing shipped /change-log-hub',
        ...overrides,
    };
}

function betaRelease(): PublicRelease {
    return release({
        id: 2,
        version: 'v1.0.1b',
        previous_version: 'v1.0.0',
        is_current: false,
        slug: 'release-v101b',
        channel: 'beta',
        channel_label: 'Beta',
        version_type: 'patch beta',
        theme: 'Beta fix',
        change_types: ['fixed'],
        topic_tags: ['internal-only'],
        tags: ['fixed', 'internal-only', 'beta'],
        summary: 'Beta-only fix for import dry-run.',
        search_text: 'v1.0.1b beta fixed internal-only import dry-run',
    });
}

const filters = [
    { value: 'all', label: 'All releases' },
    { value: 'current', label: 'Current release' },
    { value: 'beta', label: 'Beta' },
    { value: 'added', label: 'Added' },
    { value: 'fixed', label: 'Fixed' },
];

describe('ChangeLogHubIndex', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: { user: null, can: { accessStaff: false, accessAdmin: false } },
            currentRelease: { version: 'v1.1.1', slug: 'release-v111' },
        });
        window.location.hash = '';
    });

    it('filters by chip and search text', () => {
        const current = release();
        render(
            <ChangeLogHubIndex
                releases={[current, betaRelease()]}
                current={current}
                canonicalUrl="http://localhost/change-log-hub"
                filters={filters}
            />,
        );

        expect(screen.getAllByText('v1.1.1').length).toBeGreaterThan(0);
        expect(screen.getByText('v1.0.1b')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Beta' }));
        expect(screen.queryByRole('heading', { level: 1, name: 'Change Log Hub' })).toBeInTheDocument();
        expect(document.getElementById('release-v111')).toBeNull();
        expect(document.getElementById('release-v101b')).not.toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'All releases' }));
        fireEvent.change(screen.getByLabelText(/search version/i), {
            target: { value: 'change log hub' },
        });
        expect(document.getElementById('release-v111')).not.toBeNull();
        expect(document.getElementById('release-v101b')).toBeNull();
    });

    it('shows empty state when nothing matches', () => {
        const current = release();
        render(
            <ChangeLogHubIndex
                releases={[current]}
                current={current}
                canonicalUrl="http://localhost/change-log-hub"
                filters={filters}
            />,
        );

        fireEvent.change(screen.getByLabelText(/search version/i), {
            target: { value: 'does-not-exist' },
        });
        expect(screen.getByText(/no releases match/i)).toBeInTheDocument();
    });

    it('expands and collapses all release cards', () => {
        const current = release();
        const older = betaRelease();
        render(
            <ChangeLogHubIndex
                releases={[current, older]}
                current={current}
                canonicalUrl="http://localhost/change-log-hub"
                filters={filters}
            />,
        );

        const currentCard = document.getElementById('release-v111') as HTMLDetailsElement;
        const betaCard = document.getElementById('release-v101b') as HTMLDetailsElement;
        expect(currentCard.open).toBe(true);
        expect(betaCard.open).toBe(false);

        fireEvent.click(screen.getByRole('button', { name: 'Expand all' }));
        expect(currentCard.open).toBe(true);
        expect(betaCard.open).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Collapse all' }));
        expect(currentCard.open).toBe(false);
        expect(betaCard.open).toBe(false);
    });
});
