<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ url('/') }}</loc>
        <changefreq>daily</changefreq>
    </url>
    <url>
        <loc>{{ url('/change-log-hub') }}</loc>
        <changefreq>weekly</changefreq>
    </url>
@foreach ($posts as $post)
    <url>
        <loc>{{ url('/articles/'.$post->slug) }}</loc>
        <lastmod>{{ ($post->updated_at ?? $post->published_at)->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
    </url>
@endforeach
</urlset>
