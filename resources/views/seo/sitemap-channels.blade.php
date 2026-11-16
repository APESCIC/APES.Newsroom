<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($channels as $channel)
    <url>
        <loc>{{ url('/'.$channel) }}</loc>
        <changefreq>daily</changefreq>
    </url>
@endforeach
</urlset>
