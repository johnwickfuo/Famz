<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0E5138">

        @php
            // Shared by HandleInertiaRequests. Defaulted here as well, because
            // a page rendered outside the Inertia middleware still needs a
            // title rather than a PHP notice.
            $seo = $page['props']['seo'] ?? ['title' => $branding['name'], 'description' => '', 'image' => null, 'url' => url()->current(), 'type' => 'website', 'site' => $branding['name'], 'noindex' => false];
        @endphp

        {{-- The title and icon come from settings, never from a literal. --}}
        <title inertia>{{ $seo['title'] }}</title>

        @if ($seo['description'])
            <meta name="description" content="{{ $seo['description'] }}" inertia>
        @endif

        {{--
            Pages that are reachable but must never be indexed: private
            checkout links, worker profiles, anything carrying a contact
            detail. The sitemap already leaves these out; this is what stops a
            crawler that found the URL some other way.
        --}}
        @if ($seo['noindex'])
            <meta name="robots" content="noindex, nofollow" inertia>
        @endif

        <link rel="canonical" href="{{ $seo['url'] }}" inertia>

        {{--
            Open Graph and Twitter. Most sharing from this platform happens
            through WhatsApp, which reads these and does not run JavaScript —
            so they are rendered here rather than set by Vue after boot.
        --}}
        <meta property="og:type" content="{{ $seo['type'] }}" inertia>
        <meta property="og:site_name" content="{{ $seo['site'] }}" inertia>
        <meta property="og:title" content="{{ $seo['title'] }}" inertia>
        <meta property="og:url" content="{{ $seo['url'] }}" inertia>
        @if ($seo['description'])
            <meta property="og:description" content="{{ $seo['description'] }}" inertia>
        @endif
        @if ($seo['image'])
            <meta property="og:image" content="{{ $seo['image'] }}" inertia>
            <meta name="twitter:card" content="summary_large_image" inertia>
        @else
            <meta name="twitter:card" content="summary" inertia>
        @endif
        <meta name="twitter:title" content="{{ $seo['title'] }}" inertia>
        @if ($seo['description'])
            <meta name="twitter:description" content="{{ $seo['description'] }}" inertia>
        @endif

        @if ($branding['favicon_url'])
            <link rel="icon" href="{{ $branding['favicon_url'] }}">
        @endif

        {{--
            The nonce from SecurityHeaders. Ziggy and Inertia both emit inline
            script, and under a nonce-based policy an inline script without one
            simply does not run — which is the whole application not running.
        --}}
        @routes(nonce: app('csp-nonce'))
        @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
