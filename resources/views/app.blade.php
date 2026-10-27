<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'APES Newsroom') }}</title>
        @isset($meta)
        <meta inertia name="description" content="{{ $meta['description'] }}">
        <link inertia rel="canonical" href="{{ $meta['url'] }}">
        <meta inertia property="og:title" content="{{ $meta['title'] }}">
        <meta inertia property="og:description" content="{{ $meta['description'] }}">
        <meta inertia property="og:type" content="website">
        <meta inertia property="og:url" content="{{ $meta['url'] }}">
        <meta inertia property="og:image" content="{{ $meta['image'] }}">
        <meta inertia name="twitter:card" content="summary">
        <meta inertia name="twitter:title" content="{{ $meta['title'] }}">
        <meta inertia name="twitter:description" content="{{ $meta['description'] }}">
        <meta inertia name="twitter:image" content="{{ $meta['image'] }}">
        @isset($meta['rss'])
        <link inertia rel="alternate" type="application/rss+xml" title="{{ $meta['title'] }}" href="{{ $meta['rss'] }}">
        @endisset
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
