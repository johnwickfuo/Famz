{{--
    The base branded document.

    Every PDF the platform produces — receipts, quotes, certificates — renders
    inside this. The company name, logo and registered details come from
    BrandingService through the view composer, so a rename in Settings changes
    every document produced from the next request onwards.

    Type is DejaVu Sans on purpose: the platform's own faces are variable WOFF2,
    which DomPDF cannot embed, and DejaVu is the one bundled face that covers the
    Naira sign and the Yoruba/Igbo dot-below vowels a Nigerian name needs.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', $branding['name'])</title>
    <style>
        @page { margin: 24mm 18mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #14170F;
            margin: 0;
        }

        .brand-label {
            border: 2px solid #14170F;
            padding: 8px 12px;
        }

        .wordmark {
            font-size: 15pt;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #14170F;
        }

        .seam {
            border: 0;
            border-top: 2px dashed #CED1CA;
            margin: 14px 0;
        }

        .meta { font-size: 8pt; color: #575A52; }

        h1 { font-size: 20pt; margin: 0 0 6px; }
        h2 { font-size: 13pt; margin: 18px 0 6px; }

        table.details { width: 100%; border-collapse: collapse; border: 2px solid #14170F; margin: 12px 0 18px; }
        table.details th {
            width: 38%;
            text-align: left;
            padding: 7px 10px;
            background: #ECEFE8;
            font-size: 8pt;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #575A52;
            border-bottom: 1px dashed #CED1CA;
        }
        table.details td { padding: 7px 10px; border-bottom: 1px dashed #CED1CA; }
        table.details tr:last-child th, table.details tr:last-child td { border-bottom: 0; }

        .footer {
            position: fixed;
            bottom: -14mm;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #575A52;
            border-top: 2px dashed #CED1CA;
            padding-top: 6px;
        }
    </style>
</head>
<body>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;">
        <tr>
            <td style="vertical-align: middle;">
                <div class="brand-label">
                    @if ($branding['logo_url'])
                        <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}" height="30">
                    @else
                        <span class="wordmark">{{ $branding['name'] }}</span>
                    @endif
                </div>
            </td>
            <td style="vertical-align: middle; text-align: right;" class="meta">
                @if ($branding['address'])<div>{{ $branding['address'] }}</div>@endif
                @if ($branding['phone'])<div>{{ $branding['phone'] }}</div>@endif
                @if ($branding['email'])<div>{{ $branding['email'] }}</div>@endif
                @if ($branding['rc_number'])<div>RC {{ $branding['rc_number'] }}</div>@endif
            </td>
        </tr>
    </table>

    <hr class="seam">

    @yield('content')

    <div class="footer">
        {{ $branding['name'] }}@if ($branding['rc_number']) &middot; RC {{ $branding['rc_number'] }}@endif
        @if ($branding['email']) &middot; {{ $branding['email'] }}@endif
    </div>
</body>
</html>
