import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, vi } from 'vitest';

type MockLinkProps = {
    as?: string;
    children?: ReactNode;
    href: string;
    method?: string;
    [key: string]: unknown;
};

type MockHeadProps = {
    children?: ReactNode;
    title?: string;
};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    const { getInertiaMock } = await import('./inertia');

    return {
        Head: ({ children, title }: MockHeadProps) =>
            React.createElement(
                'div',
                { 'data-testid': 'document-head' },
                title ? React.createElement('title', null, title) : null,
                children,
            ),
        Link: (props: MockLinkProps) => {
            const { as = 'a', children, href, method, ...attributes } = props;
            void method;

            return React.createElement(as, as === 'a' ? { ...attributes, href } : attributes, children);
        },
        router: { post: (...args: unknown[]) => getInertiaMock().post(...args) },
        useForm: <T extends Record<string, unknown>>(initial: T) => ({
            data: initial,
            setData: vi.fn(),
            patch: vi.fn(),
            processing: false,
            errors: {},
            delete: vi.fn(),
        }),
        usePage: () => getInertiaMock().page,
    };
});

afterEach(() => {
    cleanup();
});
