<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0E5138">

        {{-- The title and icon come from settings, never from a literal. --}}
        <title inertia>{{ $branding['name'] }}</title>

        @if ($branding['favicon_url'])
            <link rel="icon" href="{{ $branding['favicon_url'] }}">
        @endif

        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
