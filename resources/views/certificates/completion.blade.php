{{--
    A training certificate. Landscape, and built so it reads properly whether
    the company turns out to have a two-word name or a six-word one, and whether
    or not a logo has been uploaded.

    Everything about the issuer comes from $issuer, never from a literal and
    never from the live branding directly: on an issued certificate that array
    is the snapshot taken the day it was awarded, so reprinting one years later
    produces the document the student was actually given.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $issuer['name'] }} — {{ __('Certificate') }}</title>
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
            letter-spacing: {{ strlen($issuer['name']) <= 10 ? '3px' : '1px' }};
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
                        {{-- Wordmark when there is no logo: a blank box would
                             be worse than a name set in type. --}}
                        @if ($issuer['logo_url'])
                            <img src="{{ $issuer['logo_url'] }}" alt="{{ $issuer['name'] }}" height="30">
                        @else
                            <span class="wordmark">{{ $issuer['name'] }}</span>
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
            {{ __('Issued by :company on :date.', ['company' => $issuer['name'], 'date' => $issuedAt->format('j F Y')]) }}
            @if ($issuer['rc_number'])
                {{ __('RC :number.', ['number' => $issuer['rc_number']]) }}
            @endif
            @if ($scorePercent !== null)
                {{ __('Final assessment: :score%.', ['score' => $scorePercent]) }}
            @endif
        </p>

        <hr class="stitch-foot">

        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align:bottom;">
                    <div class="signature-line">
                        @if ($issuer['signatory_name'])
                            <span style="font-size:10pt; font-weight:bold;">{{ $issuer['signatory_name'] }}</span><br>
                        @endif
                        <span class="stencil">
                            @if ($issuer['signatory_title'])
                                {{ $issuer['signatory_title'] }},
                            @endif
                            {{ __('for :company', ['company' => $issuer['short_name']]) }}
                        </span>
                    </div>
                </td>
                <td style="text-align:right; vertical-align:bottom;" class="stencil">
                    {{ __('Check this certificate at') }}<br>
                    {{ $verifyUrl }}
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
