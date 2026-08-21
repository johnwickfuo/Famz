@php
    /*
     * Resolved here rather than from a shared Inertia prop.
     *
     * Error pages render outside the web middleware — and a 503 pre-rendered by
     * `artisan down --render` renders before the settings table is necessarily
     * reachable. BrandingService handles that itself, falling back internally
     * when settings cannot be read, so this layout has no fallback of its own.
     * It must not: BrandingService is the only thing permitted to name the
     * company, and a rescue clause here would be a second place that does.
     */
    $brand = app(\App\Services\Branding\BrandingService::class)->payload();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $brand['name'] }} — @yield('title')</title>

    {{--
        Everything inline, and deliberately.

        A maintenance page renders when the application is down, which is
        exactly when the asset pipeline, the CDN and the database may also be
        unavailable. A page that needs a stylesheet to look right is a page
        that shows unstyled text on the day it matters. The tokens below are
        copied from the design system rather than imported for the same reason.
    --}}
    <style>
        :root {
            --wash: #ecefe8;
            --enamel: #0e5138;
            --chrome: #f5b711;
            --ink: #14170f;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--wash);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        .card {
            width: 100%;
            max-width: 32rem;
            background: #fff;
            border: 2px solid var(--ink);
            border-radius: 2px;
            box-shadow: 6px 6px 0 var(--ink);
            padding: 2rem;
        }

        .mark {
            display: inline-block;
            border: 2px solid var(--ink);
            border-radius: 2px;
            background: var(--enamel);
            color: var(--wash);
            padding: 0.35rem 0.75rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-size: 0.75rem;
        }

        h1 { font-size: 1.6rem; margin: 1.25rem 0 0.5rem; line-height: 1.2; }
        p { margin: 0 0 0.75rem; }
        .muted { color: #4a5245; font-size: 0.95rem; }

        .rule {
            border: 0;
            border-top: 2px dashed var(--chrome);
            margin: 1.5rem 0;
        }

        .contact { font-size: 0.95rem; }
        .contact a { color: var(--enamel); font-weight: 600; }

        @media (prefers-color-scheme: dark) {
            body { background: #10130d; color: var(--wash); }
            .card { background: #1b2016; border-color: var(--wash); box-shadow: 6px 6px 0 #000; }
            .muted { color: #a8b0a2; }
            .contact a { color: var(--chrome); }
        }
    </style>
</head>
<body>
    <main class="card">
        {{-- The company name comes from branding, like everywhere else. --}}
        <span class="mark">{{ $brand['name'] }}</span>

        <h1>@yield('heading')</h1>

        @yield('body')

        <hr class="rule">

        <p class="contact">
            @hasSection('contact')
                @yield('contact')
            @elseif (! empty($brand['phone']))
                Need help? Call <a href="tel:{{ $brand['phone'] }}">{{ $brand['phone'] }}</a>.
            @elseif (! empty($brand['email']))
                Need help? Write to <a href="mailto:{{ $brand['email'] }}">{{ $brand['email'] }}</a>.
            @else
                <a href="{{ url('/') }}">Go back to the home page</a>.
            @endif
        </p>
    </main>
</body>
</html>
