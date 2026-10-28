import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../../Components/Layout/PublicLayout';

type PostCard = {
    title: string;
    slug: string;
    excerpt: string | null;
    channel: string;
};

type Paginated = {
    data: PostCard[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

export default function TagArchive({ tag, posts }: { tag: { name: string; slug: string }; posts: Paginated }) {
    return (
        <PublicLayout>
            <Head title={`Tag: ${tag.name}`} />
            <main id="main-content" className="mx-auto max-w-public px-5 py-12 sm:px-6">
                <h1 className="text-2xl font-bold text-sg-ink">Tag: {tag.name}</h1>
                {posts.data.length === 0 ? (
                    <p className="mt-6 text-muted">No published stories with this tag.</p>
                ) : (
                    <ul className="mt-6 space-y-4">
                        {posts.data.map((post) => (
                            <li key={post.slug} className="sg-card p-5">
                                <p className="text-xs font-bold tracking-wide text-sg-teal uppercase">{post.channel}</p>
                                <Link href={`/articles/${post.slug}`} className="text-lg font-bold text-sg-ink hover:text-sg-teal hover:underline">
                                    {post.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </main>
        </PublicLayout>
    );
}
