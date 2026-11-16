<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#E8F6F6">

        <title inertia>{{ config('app.name', 'APES Newsroom') }}</title>
        @isset($meta)
        <meta inertia name="description" content="{{ $meta['description'] }}">
        @isset($meta['robots'])
        <meta inertia name="robots" content="{{ $meta['robots'] }}">
        @endisset
        <link inertia rel="canonical" href="{{ $meta['url'] }}">
        <link inertia rel="alternate" hreflang="{{ $meta['hreflang'] ?? 'en-GB' }}" href="{{ $meta['url'] }}">
        <link inertia rel="alternate" hreflang="x-default" href="{{ $meta['url'] }}">
        <meta inertia property="og:title" content="{{ $meta['title'] }}">
        <meta inertia property="og:description" content="{{ $meta['description'] }}">
        <meta inertia property="og:type" content="{{ $meta['type'] ?? 'website' }}">
        <meta inertia property="og:url" content="{{ $meta['url'] }}">
        <meta inertia property="og:site_name" content="{{ $meta['site_name'] ?? 'APES Newsroom' }}">
        <meta inertia property="og:locale" content="{{ $meta['locale'] ?? 'en_GB' }}">
        <meta inertia property="og:image" content="{{ $meta['image'] }}">
        @isset($meta['image_width'])
        <meta inertia property="og:image:width" content="{{ $meta['image_width'] }}">
        @endisset
        @isset($meta['image_height'])
        <meta inertia property="og:image:height" content="{{ $meta['image_height'] }}">
        @endisset
        @isset($meta['image_alt'])
        <meta inertia property="og:image:alt" content="{{ $meta['image_alt'] }}">
        @endisset
        @if(($meta['type'] ?? null) === 'article' && !empty($meta['article']))
        @isset($meta['article']['published_time'])
        <meta inertia property="article:published_time" content="{{ $meta['article']['published_time'] }}">
        @endisset
        @isset($meta['article']['modified_time'])
        <meta inertia property="article:modified_time" content="{{ $meta['article']['modified_time'] }}">
        @endisset
        @isset($meta['article']['author'])
        <meta inertia property="article:author" content="{{ $meta['article']['author'] }}">
        @endisset
        @isset($meta['article']['section'])
        <meta inertia property="article:section" content="{{ $meta['article']['section'] }}">
        @endisset
        @foreach(($meta['article']['tags'] ?? []) as $tag)
        <meta inertia property="article:tag" content="{{ $tag }}">
        @endforeach
        @endif
        <meta inertia name="twitter:card" content="{{ $meta['twitter_card'] ?? 'summary_large_image' }}">
        <meta inertia name="twitter:title" content="{{ $meta['twitter_title'] ?? $meta['title'] }}">
        <meta inertia name="twitter:description" content="{{ $meta['twitter_description'] ?? $meta['description'] }}">
        <meta inertia name="twitter:image" content="{{ $meta['twitter_image'] ?? $meta['image'] }}">
        <meta inertia name="twitter:url" content="{{ $meta['url'] }}">
        @isset($meta['twitter_site'])
        <meta inertia name="twitter:site" content="{{ $meta['twitter_site'] }}">
        @endisset
        @isset($meta['image_alt'])
        <meta inertia name="twitter:image:alt" content="{{ $meta['image_alt'] }}">
        @endisset
        @isset($meta['verification']['google'])
        <meta inertia name="google-site-verification" content="{{ $meta['verification']['google'] }}">
        @endisset
        @isset($meta['verification']['bing'])
        <meta inertia name="msvalidate.01" content="{{ $meta['verification']['bing'] }}">
        @endisset
        @isset($meta['rss'])
        <link inertia rel="alternate" type="application/rss+xml" title="{{ $meta['title'] }}" href="{{ $meta['rss'] }}">
        @endisset
        @foreach(($meta['json_ld'] ?? []) as $graph)
        <script type="application/ld+json">{!! json_encode($graph, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}</script>
        @endforeach
        @endisset

        <link rel="icon" type="image/png" sizes="32x32" href="/brand/apes-favicon-32.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/brand/apes-favicon-192.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/brand/apes-touch-icon-180.png">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
