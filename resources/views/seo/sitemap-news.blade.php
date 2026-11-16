<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
@foreach ($posts as $post)
    <url>
        <loc>{{ url('/articles/'.$post->slug) }}</loc>
        <lastmod>{{ ($post->updated_at ?? $post->published_at)->toAtomString() }}</lastmod>
        <news:news>
            <news:publication>
                <news:name>APES Newsroom</news:name>
                <news:language>en</news:language>
            </news:publication>
            <news:publication_date>{{ $post->published_at?->toAtomString() }}</news:publication_date>
            <news:title>{{ $post->title }}</news:title>
        </news:news>
    </url>
@endforeach
</urlset>
