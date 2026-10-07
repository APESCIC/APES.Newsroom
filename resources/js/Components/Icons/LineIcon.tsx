import type { SVGProps } from 'react';

export type IconName =
    | 'arrow-right'
    | 'building'
    | 'clinic'
    | 'document'
    | 'home'
    | 'menu'
    | 'plus'
    | 'review'
    | 'search'
    | 'settings'
    | 'shelter'
    | 'shield'
    | 'tree'
    | 'user'
    | 'x';

export default function LineIcon({ name, ...props }: { name: IconName } & SVGProps<SVGSVGElement>) {
    const paths: Record<IconName, React.ReactNode> = {
        'arrow-right': <path d="M5 12h14m-5-5 5 5-5 5" />,
        building: (
            <>
                <path d="M4 21V8l8-5 8 5v13" />
                <path d="M8 21v-5h8v5M8 10h.01M12 10h.01M16 10h.01" />
            </>
        ),
        clinic: (
            <>
                <path d="M9 3h6v5h5v8h-5v5H9v-5H4V8h5z" />
                <path d="M12 9v6M9 12h6" />
            </>
        ),
        document: (
            <>
                <path d="M6 3h9l4 4v14H6z" />
                <path d="M14 3v5h5M9 13h6M9 17h6" />
            </>
        ),
        home: (
            <>
                <path d="m3 11 9-8 9 8" />
                <path d="M5 10v11h14V10M9 21v-7h6v7" />
            </>
        ),
        menu: <path d="M4 7h16M4 12h16M4 17h16" />,
        plus: <path d="M12 5v14M5 12h14" />,
        review: (
            <>
                <path d="M4 4h16v14H7l-3 3z" />
                <path d="m8 11 2.5 2.5L16 8" />
            </>
        ),
        search: (
            <>
                <circle cx="11" cy="11" r="7" />
                <path d="m16.5 16.5 4 4" />
            </>
        ),
        settings: (
            <>
                <path d="M4 6h9m4 0h3M4 12h3m4 0h9M4 18h11m4 0h1" />
                <circle cx="15" cy="6" r="2" />
                <circle cx="9" cy="12" r="2" />
                <circle cx="17" cy="18" r="2" />
            </>
        ),
        shelter: (
            <>
                <path d="m3 11 9-8 9 8M5 10v11h14V10" />
                <path d="M9.5 15.5c.8-1.7 4.2-1.7 5 0 .8 1.8-2.5 3.5-2.5 3.5s-3.3-1.7-2.5-3.5Z" />
            </>
        ),
        shield: (
            <>
                <path d="M12 3 4.5 6v5c0 4.7 3 8.3 7.5 10 4.5-1.7 7.5-5.3 7.5-10V6z" />
                <path d="m9 12 2 2 4-4" />
            </>
        ),
        tree: (
            <>
                <path d="M12 21v-8" />
                <path d="M12 13c-4.5 0-7-2.7-7-6 4.5 0 7 2.7 7 6Zm0 3c4.5 0 7-2.7 7-6-4.5 0-7 2.7-7 6Z" />
            </>
        ),
        user: (
            <>
                <circle cx="12" cy="8" r="4" />
                <path d="M4 21c.7-4 3.3-6 8-6s7.3 2 8 6" />
            </>
        ),
        x: <path d="m6 6 12 12M18 6 6 18" />,
    };

    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {paths[name]}
        </svg>
    );
}
