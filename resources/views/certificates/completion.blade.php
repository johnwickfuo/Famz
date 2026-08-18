{{--
    A training certificate. Landscape, and built so it reads properly whether
    the company turns out to have a two-word name or a six-word one, and whether
    or not a logo has been uploaded.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $branding['name'] }} — {{ __('Certificate') }}</title>
    <style>
        @page { margin: 0; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #14170F;
            margin: 0;
        }

        .sheet {
            margin: 10mm;
            padding: 12mm 14mm;
            border: 3px solid #14170F;
            height: 168mm;
        }

        /* The seam, carried onto paper. */
        .stitch { border: 0; border-top: 3px dashed #F5B711; margin: 0 0 10mm; }
        .stitch-foot { border: 0; border-top: 3px dashed #CED1CA; margin: 8mm 0 5mm; }

        .brand-label { display: inline-block; border: 2px solid #14170F; padding: 7px 12px; }

        .wordmark {
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: {{ strlen($branding['name']) <= 10 ? '3px' : '1px' }};
            text-transform: uppercase;
        }

        .stencil {
            font-size: 8pt;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #575A52;
        }

        .holder {
            font-size: {{ strlen($holderName) > 28 ? '26pt' : '34pt' }};
            font-weight: bold;
            line-height: 1.1;
            margin: 4mm 0 2mm;
        }

        .course { font-size: 15pt; margin: 0 0 6mm; }

        .signature-line { border-top: 2px solid #14170F; padding-top: 4px; width: 62mm; }
    </style>
</head>
<body>
    <div class="sheet">
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8mm;">
            <tr>
                <td>
                    <div class="brand-label">
                        @if ($branding['logo_url'])
                            <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}" height="30">
                        @else
                            <span class="wordmark">{{ $branding['name'] }}</span>
                        @endif
                    </div>
                </td>
                <td style="text-align:right;" class="stencil">
                    {{ __('Certificate of completion') }}<br>
                    {{ $reference }}
                </td>
            </tr>
        </table>

        <hr class="stitch">

        <p class="stencil" style="margin:0;">{{ __('This is to certify that') }}</p>

        <p class="holder">{{ $holderName }}</p>

        <p class="stencil" style="margin:0;">{{ __('has completed the course') }}</p>

        <p class="course">{{ $courseTitle }}</p>

        <p style="font-size:10pt; max-width:150mm;">
            {{ branded(__('Issued by {company} on :date.', ['date' => $issuedAt->format('j F Y')])) }}
            @if ($branding['rc_number'])
                {{ __('RC :number.', ['number' => $branding['rc_number']]) }}
            @endif
        </p>

        <hr class="stitch-foot">

        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align:bottom;">
                    <div class="signature-line stencil">{{ __('For :company', ['company' => $branding['short_name']]) }}</div>
                </td>
                <td style="text-align:right; vertical-align:bottom;" class="stencil">
                    {{ __('Verify this certificate with the reference above') }}<br>
                    @if ($branding['email']){{ $branding['email'] }}@endif
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
