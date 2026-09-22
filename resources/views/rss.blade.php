<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $link }}</link>
        <description>{{ $description }}</description>
        <language>en-gb</language>
        <atom:link href="{{ $self }}" rel="self" type="application/rss+xml" />
        @foreach ($posts as $post)
        <item>
            <title>{{ $post->title }}</title>
            <link>{{ url('/articles/'.$post->slug) }}</link>
            <guid isPermaLink="true">{{ url('/articles/'.$post->slug) }}</guid>
            <pubDate>{{ $post->published_at->toRfc2822String() }}</pubDate>
            <description><![CDATA[{{ $post->excerpt }}]]></description>
            <author>{{ $post->author->email }} ({{ $post->author->name }})</author>
        </item>
        @endforeach
    </channel>
</rss>
