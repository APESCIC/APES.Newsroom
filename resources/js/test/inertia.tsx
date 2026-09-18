import { vi } from 'vitest';
import type { SharedPageProps } from '../types/page';

type VisitOptions = {
    preserveScroll?: boolean;
    onSuccess?: (page: { props: SharedPageProps & Record<string, unknown> }) => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

export type MockVisit = {
    url: string;
    options: VisitOptions;
    data: unknown;
    status: 'pending' | 'success' | 'error';
};

const inertiaMock = {
    page: {
        props: {
            appName: 'APES Newsroom',
            auth: {
                user: null,
                can: { accessStaff: false, accessAdmin: false },
            },
        } as SharedPageProps,
    },
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    visits: [] as MockVisit[],
};

export function setMockPage(props: SharedPageProps) {
    inertiaMock.page = { props };
    inertiaMock.post.mockReset();
    inertiaMock.patch.mockReset();
    inertiaMock.delete.mockReset();
    inertiaMock.visits = [];
}

export function getInertiaMock() {
    return inertiaMock;
}

export function recordVisit(method: 'post' | 'patch' | 'delete', url: string, options: VisitOptions, data: unknown) {
    const visit: MockVisit = { url, options, data, status: 'pending' };
    inertiaMock.visits.push(visit);
    inertiaMock[method](url, options, data);

    return visit;
}
