import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, vi } from 'vitest';
import { getInertiaMock as getTestInertiaMock, recordVisit } from './inertia';

type MockLinkProps = {
    as?: string;
    children?: ReactNode;
    href: string;
    method?: string;
    [key: string]: unknown;
};

type MockHeadProps = {
    children?: ReactNode;
    [key: string]: unknown;
};

type VisitOptions = {
    preserveScroll?: boolean;
    onSuccess?: (page: { props: Record<string, unknown> }) => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    const { getInertiaMock } = await import('./inertia');

    return {
        Head: ({ children }: MockHeadProps) => React.createElement(React.Fragment, null, children),
        Link: (props: MockLinkProps) => {
            const { as = 'a', children, href, method, ...attributes } = props;
            void method;

            return React.createElement(as, as === 'a' ? { ...attributes, href } : attributes, children);
        },
        router: {
            post: (...args: unknown[]) => getInertiaMock().post(...args),
            patch: (...args: unknown[]) => getInertiaMock().patch(...args),
            delete: (...args: unknown[]) => getInertiaMock().delete(...args),
        },
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setFormData] = React.useState(initial);
            const [defaultData, setDefaultData] = React.useState(initial);
            const [processing, setProcessing] = React.useState(false);
            const [errors, setErrors] = React.useState<Record<string, string>>({});
            const transformRef = React.useRef<(values: T) => unknown>((values) => values);

            const setData = React.useCallback((
                keyOrData: keyof T | Partial<T> | ((current: T) => T),
                value?: T[keyof T],
            ) => {
                if (typeof keyOrData === 'function') {
                    setFormData(keyOrData);
                    return;
                }
                if (typeof keyOrData === 'object') {
                    setFormData((current) => ({ ...current, ...keyOrData }));
                    return;
                }
                setFormData((current) => ({ ...current, [keyOrData]: value }));
            }, []);

            const submit = React.useCallback(
                (method: 'post' | 'patch' | 'delete', url: string, options?: VisitOptions) => {
                    setProcessing(true);
                    const visit = recordVisit(method, url, {
                        ...options,
                        onSuccess: (page) => {
                            visit.status = 'success';
                            setErrors({});
                            setProcessing(false);
                            options?.onSuccess?.(page);
                        },
                        onError: (nextErrors) => {
                            visit.status = 'error';
                            setErrors(nextErrors);
                            setProcessing(false);
                            options?.onError?.(nextErrors);
                        },
                        onFinish: () => {
                            setProcessing(false);
                            options?.onFinish?.();
                        },
                    }, transformRef.current(data));
                },
                [data],
            );

            const post = React.useCallback(
                (url: string, options?: VisitOptions) => submit('post', url, options),
                [submit],
            );
            const patch = React.useCallback(
                (url: string, options?: VisitOptions) => submit('patch', url, options),
                [submit],
            );
            const destroy = React.useCallback(
                (url: string, options?: VisitOptions) => submit('delete', url, options),
                [submit],
            );
            const transform = React.useCallback((callback: (values: T) => unknown) => {
                transformRef.current = callback;
            }, []);
            const setDefaults = React.useCallback((values?: T) => {
                setDefaultData(values ?? data);
            }, [data]);
            const reset = React.useCallback((...fields: (keyof T)[]) => {
                if (fields.length === 0) {
                    setFormData(defaultData);
                    return;
                }

                setFormData((current) => {
                    const restored = { ...current };
                    fields.forEach((field) => {
                        restored[field] = defaultData[field];
                    });

                    return restored;
                });
            }, [defaultData]);

            return {
                data,
                setData,
                post,
                patch,
                delete: destroy,
                processing,
                errors,
                transform,
                setDefaults,
                isDirty: JSON.stringify(data) !== JSON.stringify(defaultData),
                reset,
            };
        },
        usePage: () => getInertiaMock().page,
    };
});

afterEach(() => {
    cleanup();
    const inertiaMock = getTestInertiaMock();
    inertiaMock.post.mockReset();
    inertiaMock.patch.mockReset();
    inertiaMock.delete.mockReset();
    inertiaMock.visits = [];
});
