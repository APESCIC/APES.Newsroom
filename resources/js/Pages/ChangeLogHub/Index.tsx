import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import PublicLayout from '../../Components/Layout/PublicLayout';

export type PublicRelease = {
    id: number;
    version: string;
    previous_version: string | null;
    released_at: string | null;
    channel: string;
    channel_label: string;
    version_type: string | null;
    theme: string | null;
    is_current: boolean;
    slug: string;
    change_types: string[];
    topic_tags: string[];
    tags: string[];
    summary: string;
    detailed_changes: string[];
    affected_areas: string[];
    version_decision: string[];
    validation: string[];
    search_text: string;
};

type FilterOption = {
    value: string;
    label: string;
};

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value));
}

function labelize(tag: string): string {
    if (tag === 'public-facing') {
        return 'Public-facing';
    }
    if (tag === 'internal-only') {
        return 'Internal-only';
    }

    return tag.charAt(0).toUpperCase() + tag.slice(1);
}

function matchesFilter(release: PublicRelease, filter: string): boolean {
    if (filter === 'all') {
        return true;
    }

    return release.tags.includes(filter);
}

function matchesSearch(release: PublicRelease, query: string): boolean {
    if (!query.trim()) {
        return true;
    }

    return release.search_text.includes(query.trim().toLowerCase());
}

function SectionList({ title, items }: { title: string; items: string[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section className="mt-5">
            <h3 className="text-base font-semibold text-on-glass">{title}</h3>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-on-glass-muted">
                {items.map((item) => (
                    <li key={item}>{item}</li>
                ))}
            </ul>
        </section>
    );
}

function ReleaseCard({
    release,
    open,
    onToggle,
}: {
    release: PublicRelease;
    open: boolean;
    onToggle: (slug: string, nextOpen: boolean) => void;
}) {
    return (
        <details
            id={release.slug}
            className="glass-form-panel release-card overflow-hidden"
            open={open}
            data-tags={release.tags.join(' ')}
            onToggle={(event) => onToggle(release.slug, event.currentTarget.open)}
        >
            <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                <span className="release-version text-lg font-semibold text-on-glass">{release.version}</span>
                <span className="release-date text-sm text-on-glass-muted">{formatDate(release.released_at)}</span>
            </summary>
            <div className="release-body border-t border-white/15 px-5 py-5 sm:px-6">
                <div className="flex flex-wrap gap-2">
                    <span className="rounded-control bg-white/15 px-2.5 py-1 text-xs font-semibold tracking-wide text-on-glass uppercase">
                        Version {release.version}
                    </span>
                    <span className="rounded-control bg-white/15 px-2.5 py-1 text-xs font-semibold tracking-wide text-on-glass uppercase">
                        {release.channel_label}
                    </span>
                    {release.change_types.map((type) => (
                        <span
                            key={type}
                            className="rounded-control bg-brand-teal/25 px-2.5 py-1 text-xs font-semibold tracking-wide text-on-glass uppercase"
                        >
                            {labelize(type)}
                        </span>
                    ))}
                    {release.topic_tags.map((tag) => (
                        <span
                            key={tag}
                            className="rounded-control bg-white/10 px-2.5 py-1 text-xs font-semibold tracking-wide text-on-glass-muted uppercase"
                        >
                            {labelize(tag)}
                        </span>
                    ))}
                </div>

                <section className="mt-5">
                    <h3 className="text-base font-semibold text-on-glass">Summary</h3>
                    <p className="mt-2 text-sm text-on-glass-muted">{release.summary}</p>
                </section>

                <SectionList title="Detailed changes" items={release.detailed_changes} />
                <SectionList title="Affected areas" items={release.affected_areas} />
                <SectionList title="Version decision" items={release.version_decision} />
                <SectionList title="Validation" items={release.validation} />
            </div>
        </details>
    );
}

export default function ChangeLogHubIndex({
    releases,
    current,
    canonicalUrl,
    filters,
}: {
    releases: PublicRelease[];
    current: PublicRelease | null;
    canonicalUrl: string;
    filters: FilterOption[];
}) {
    const [query, setQuery] = useState('');
    const [activeFilter, setActiveFilter] = useState('all');
    const [openSlugs, setOpenSlugs] = useState<Record<string, boolean>>(() => {
        const initial: Record<string, boolean> = {};
        for (const release of releases) {
            initial[release.slug] = release.is_current;
        }
        return initial;
    });

    useEffect(() => {
        const hash = window.location.hash.replace(/^#/, '');
        if (!hash) {
            return;
        }

        setOpenSlugs((prev) => ({ ...prev, [hash]: true }));
        requestAnimationFrame(() => {
            document.getElementById(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }, []);

    const visibleReleases = useMemo(
        () => releases.filter((release) => matchesFilter(release, activeFilter) && matchesSearch(release, query)),
        [releases, activeFilter, query],
    );

    const expandAll = () => {
        const next: Record<string, boolean> = {};
        for (const release of releases) {
            next[release.slug] = true;
        }
        setOpenSlugs(next);
    };

    const collapseAll = () => {
        const next: Record<string, boolean> = {};
        for (const release of releases) {
            next[release.slug] = false;
        }
        setOpenSlugs(next);
    };

    const onToggle = (slug: string, nextOpen: boolean) => {
        setOpenSlugs((prev) => {
            if (prev[slug] === nextOpen) {
                return prev;
            }

            return { ...prev, [slug]: nextOpen };
        });
    };

    const description =
        'Track every major APES Newsroom release, including updates, fixes, compliance changes, and user-facing improvements.';

    return (
        <PublicLayout>
            <Head title="Change Log Hub">
                <meta head-key="description" name="description" content={description} />
                <link head-key="canonical" rel="canonical" href={canonicalUrl} />
                <meta head-key="og:title" property="og:title" content="Change Log Hub | APES Newsroom" />
                <meta head-key="og:description" property="og:description" content={description} />
                <meta head-key="og:url" property="og:url" content={canonicalUrl} />
                <meta head-key="og:type" property="og:type" content="website" />
                <meta head-key="twitter:card" name="twitter:card" content="summary" />
                <meta head-key="twitter:title" name="twitter:title" content="Change Log Hub | APES Newsroom" />
                <meta head-key="twitter:description" name="twitter:description" content={description} />
                <script type="application/ld+json">
                    {JSON.stringify({
                        '@context': 'https://schema.org',
                        '@type': 'WebPage',
                        name: 'Change Log Hub',
                        description,
                        url: canonicalUrl,
                        isPartOf: {
                            '@type': 'WebSite',
                            name: 'APES Newsroom',
                            url: canonicalUrl.replace(/\/change-log-hub\/?$/, '/'),
                        },
                    })}
                </script>
            </Head>

            <main id="main-content" className="mx-auto max-w-public px-5 py-10 sm:px-6">
                <nav aria-label="Breadcrumb" className="text-sm text-on-glass-muted">
                    <ol className="flex flex-wrap items-center gap-2">
                        <li>
                            <Link href="/" className="hover:text-on-glass">
                                Home
                            </Link>
                        </li>
                        <li aria-hidden="true">/</li>
                        <li className="text-on-glass" aria-current="page">
                            Change Log Hub
                        </li>
                    </ol>
                </nav>

                <header className="glass-hero mt-6 px-6 py-8 sm:px-8">
                    <p className="eyebrow-on-glass">Release history</p>
                    <h1 className="display-headline-on-glass mt-2">Change Log Hub</h1>
                    <p className="mt-3 max-w-2xl text-on-glass-muted">{description}</p>

                    <div className="mt-5 flex flex-wrap gap-2">
                        {current && (
                            <span className="rounded-control bg-white/15 px-3 py-1.5 text-xs font-semibold tracking-wide text-on-glass uppercase">
                                Current version {current.version}
                            </span>
                        )}
                        {current?.version_type && (
                            <span className="rounded-control bg-white/15 px-3 py-1.5 text-xs font-semibold tracking-wide text-on-glass uppercase">
                                {current.version_type}
                            </span>
                        )}
                        {current?.theme && (
                            <span className="rounded-control bg-brand-teal/25 px-3 py-1.5 text-xs font-semibold tracking-wide text-on-glass uppercase">
                                {current.theme}
                            </span>
                        )}
                    </div>

                    <div className="mt-6 flex flex-wrap gap-3">
                        <button type="button" className="button-primary min-h-11 px-4" onClick={expandAll}>
                            Expand all releases
                        </button>
                        {current && (
                            <a href={`#${current.slug}`} className="button-glass inline-flex min-h-11 items-center px-4">
                                View current release
                            </a>
                        )}
                    </div>
                </header>

                <section className="release-tools mt-8" aria-label="Release filters">
                    <label htmlFor="release-search" className="sr-only">
                        Search version, changes, compliance or footer
                    </label>
                    <input
                        id="release-search"
                        className="form-input release-search w-full"
                        type="search"
                        placeholder="Search version, changes, compliance or footer"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />

                    <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="Filter releases">
                        {filters.map((filter) => {
                            const active = activeFilter === filter.value;

                            return (
                                <button
                                    key={filter.value}
                                    type="button"
                                    data-release-filter={filter.value}
                                    aria-pressed={active}
                                    className={`min-h-11 rounded-control px-3 text-sm font-semibold transition-colors ${
                                        active
                                            ? 'bg-brand-teal text-brand-ink'
                                            : 'bg-white/10 text-on-glass hover:bg-white/20'
                                    }`}
                                    onClick={() => setActiveFilter(filter.value)}
                                >
                                    {filter.label}
                                </button>
                            );
                        })}
                    </div>

                    <div className="mt-4 flex flex-wrap gap-3">
                        <button type="button" className="button-glass min-h-11 px-4" onClick={expandAll}>
                            Expand all
                        </button>
                        <button type="button" className="button-glass min-h-11 px-4" onClick={collapseAll}>
                            Collapse all
                        </button>
                    </div>
                </section>

                <section className="mt-8 flex flex-col gap-4" aria-label="Releases">
                    {visibleReleases.length === 0 ? (
                        <p className="glass-form-panel px-5 py-8 text-center text-on-glass-muted">
                            No releases match your search or filters.
                        </p>
                    ) : (
                        visibleReleases.map((release) => (
                            <ReleaseCard
                                key={release.id}
                                release={release}
                                open={Boolean(openSlugs[release.slug])}
                                onToggle={onToggle}
                            />
                        ))
                    )}
                </section>
            </main>
        </PublicLayout>
    );
}
