import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

type PostCard = {
    title: string;
    slug: string;
    excerpt: string | null;
    channel: string;
    channel_slug: string;
    author: string;
    published_at: string | null;
};

type Paginated = {
    data: PostCard[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

function ArchiveShell({
    title,
    heading,
    posts,
}: {
    title: string;
    heading: string;
    posts: Paginated;
}) {
    return (
        <PublicLayout>
            <Head title={title} />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <h1 className="text-2xl font-bold text-sg-ink">{heading}</h1>
                {posts.data.length === 0 ? (
                    <p className="mt-6 text-muted">No published stories in this archive.</p>
                ) : (
                    <ul className="mt-6 space-y-4">
                        {posts.data.map((post) => (
                            <li key={post.slug} className="sg-card p-5">
                                <p className="text-xs font-bold tracking-wide text-sg-teal uppercase">{post.channel}</p>
                                <Link href={`/articles/${post.slug}`} className="text-lg font-bold text-sg-ink hover:text-sg-teal hover:underline">
                                    {post.title}
                                </Link>
                                {post.excerpt && <p className="mt-1 text-sm text-muted">{post.excerpt}</p>}
                            </li>
                        ))}
                    </ul>
                )}
                <nav className="mt-8 flex flex-wrap gap-2 text-sm text-sg-ink" aria-label="Pagination">
                    {posts.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={`${link.label}-${index}`}
                                href={link.url}
                                className={link.active ? 'font-semibold underline' : 'underline'}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span key={`${link.label}-${index}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ),
                    )}
                </nav>
            </main>
        </PublicLayout>
    );
}

export default function AuthorArchive({
    author,
    posts,
}: {
    author: { id: number; name: string };
    posts: Paginated;
}) {
    return <ArchiveShell title={`Articles by ${author.name}`} heading={`Articles by ${author.name}`} posts={posts} />;
}
