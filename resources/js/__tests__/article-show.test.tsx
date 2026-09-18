import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import ArticleShow, { articleCanonicalUrl, type Article } from '../Pages/Articles/Show';
import { setMockPage } from '../test/inertia';

const articleUrl = 'http://localhost/articles/hero-story';

function article(overrides: Partial<Article> = {}): Article {
    return {
        title: 'Capuchin corridor update',
        slug: 'hero-story',
        excerpt: 'A field note from the rescue centre.',
        html: '<p>Story body</p>',
        channel: 'APES CIC',
        channel_slug: 'apes-cic',
        author: 'Jane Keeper',
        author_id: 7,
        published_at: '2026-08-05T09:00:00+00:00',
        meta_title: 'Capuchin corridor update | APES Newsroom',
        meta_description: 'A field note from the rescue centre.',
        tags: [{ name: 'Welfare', slug: 'welfare' }],
        hero_image: 'https://example.test/hero.jpg',
        hero_image_alt: 'A capuchin monkey in habitat',
        hero_image_caption: 'Morning light in the enclosure',
        hero_image_credit: 'APES CIC / Jane Keeper',
        canonical_url: 'https://canonical.example/hero-story',
        url: articleUrl,
        ...overrides,
    };
}

function headEl(selector: string) {
    return document.querySelector(selector);
}

function structuredData() {
    const script = document.querySelector('script[type="application/ld+json"]');
    expect(script).not.toBeNull();

    return JSON.parse(script?.textContent ?? '{}') as Record<string, unknown>;
}

describe('public article hero media and social metadata', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: null,
                can: { accessStaff: false, accessAdmin: false },
            },
        });
    });

    it('renders stored hero media, canonical override, and complete social metadata', () => {
        render(<ArticleShow article={article()} />);

        const figure = screen.getByRole('img', { name: 'A capuchin monkey in habitat' }).closest('figure');
        expect(figure).not.toBeNull();
        expect(screen.getByRole('img', { name: 'A capuchin monkey in habitat' })).toHaveAttribute(
            'src',
            'https://example.test/hero.jpg',
        );
        expect(screen.getByRole('img', { name: 'A capuchin monkey in habitat' })).toHaveClass(
            'h-auto',
            'w-full',
            'max-w-full',
        );
        expect(figure).toHaveTextContent('Morning light in the enclosure');
        expect(figure).toHaveTextContent('APES CIC / Jane Keeper');

        expect(headEl('link[rel="canonical"]')).toHaveAttribute('href', 'https://canonical.example/hero-story');
        expect(headEl('meta[property="og:url"]')).toHaveAttribute('content', 'https://canonical.example/hero-story');
        expect(headEl('meta[property="og:image"]')).toHaveAttribute('content', 'https://example.test/hero.jpg');
        expect(headEl('meta[name="twitter:url"]')).toHaveAttribute('content', 'https://canonical.example/hero-story');
        expect(headEl('meta[name="twitter:image"]')).toHaveAttribute('content', 'https://example.test/hero.jpg');
        expect(headEl('meta[name="twitter:card"]')).toHaveAttribute('content', 'summary_large_image');
        expect(headEl('meta[name="robots"]')).toBeNull();

        expect(structuredData()).toMatchObject({
            '@type': 'Article',
            url: 'https://canonical.example/hero-story',
            image: 'https://example.test/hero.jpg',
        });
        expect(articleCanonicalUrl(article())).toBe('https://canonical.example/hero-story');
    });

    it('omits empty hero markup and image metadata, falling back to the article URL', () => {
        render(
            <ArticleShow
                article={article({
                    hero_image: null,
                    hero_image_alt: null,
                    hero_image_caption: null,
                    hero_image_credit: null,
                    canonical_url: null,
                })}
            />,
        );

        expect(screen.queryByRole('figure')).not.toBeInTheDocument();
        expect(within(screen.getByRole('article')).queryByRole('img')).not.toBeInTheDocument();

        expect(headEl('link[rel="canonical"]')).toHaveAttribute('href', articleUrl);
        expect(headEl('meta[property="og:url"]')).toHaveAttribute('content', articleUrl);
        expect(headEl('meta[property="og:image"]')).toBeNull();
        expect(headEl('meta[name="twitter:url"]')).toHaveAttribute('content', articleUrl);
        expect(headEl('meta[name="twitter:image"]')).toBeNull();
        expect(headEl('meta[name="twitter:card"]')).toHaveAttribute('content', 'summary');

        const data = structuredData();
        expect(data.url).toBe(articleUrl);
        expect(data).not.toHaveProperty('image');
        expect(articleCanonicalUrl(article({ canonical_url: null }))).toBe(articleUrl);
    });

    it('omits figcaption when caption and credit are absent', () => {
        render(
            <ArticleShow
                article={article({
                    hero_image_caption: null,
                    hero_image_credit: null,
                })}
            />,
        );

        const figure = screen.getByRole('img', { name: 'A capuchin monkey in habitat' }).closest('figure');
        expect(figure).not.toBeNull();
        expect(figure?.querySelector('figcaption')).toBeNull();
    });

    it('keeps preview pages noindex and still emits canonical metadata', () => {
        render(<ArticleShow article={article()} preview />);

        expect(screen.getByText('Preview — not indexed')).toBeInTheDocument();
        expect(headEl('meta[name="robots"]')).toHaveAttribute('content', 'noindex,nofollow');
        expect(headEl('link[rel="canonical"]')).toHaveAttribute('href', 'https://canonical.example/hero-story');
    });

    it('renders untrusted caption and credit as text, not markup', () => {
        const { container } = render(
            <ArticleShow
                article={article({
                    hero_image_caption: '<img src=x onerror=alert(1)>Caption',
                    hero_image_credit: '<script>alert(1)</script>Credit',
                })}
            />,
        );

        const figure = screen.getByRole('img', { name: 'A capuchin monkey in habitat' }).closest('figure');
        expect(figure).toHaveTextContent('<img src=x onerror=alert(1)>Caption');
        expect(figure).toHaveTextContent('<script>alert(1)</script>Credit');
        expect(container.querySelector('figure img[src="x"]')).not.toBeInTheDocument();
        expect(container.querySelector('figure script')).not.toBeInTheDocument();
    });
});
