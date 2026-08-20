{{--
    A consultation report, as a document the client keeps.

    Everything about the issuer comes from $issuer, never from a literal: the
    company can be renamed in Settings and the next report printed says the new
    name, exactly as the site and the emails do.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 16mm 15mm 20mm; }

        body { font-family: 'DejaVu Sans', sans-serif; color: #14170F; font-size: 10.5pt; line-height: 1.55; }

        .stencil { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #575A52; }

        h1 { font-size: 17pt; margin: 3mm 0 1mm; line-height: 1.2; }
        h2 { font-size: 11.5pt; margin: 8mm 0 2mm; }

        /* The seam, carried onto paper. */
        .stitch { border: 0; border-top: 3px dashed #F5B711; margin: 4mm 0 6mm; }
        .rule { border: 0; border-top: 2px solid #CED1CA; margin: 6mm 0 4mm; }

        table.facts { width: 100%; border-collapse: collapse; margin: 4mm 0; }
        table.facts td { padding: 2mm 3mm; border: 1px solid #CED1CA; vertical-align: top; }
        table.facts td.key { width: 38mm; color: #575A52; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 1px; }

        .body { white-space: pre-line; }

        .foot { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8pt; color: #575A52; }
    </style>
</head>
<body>
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <p class="stencil" style="margin:0;">{{ $issuer['name'] }}</p>
                <h1>{{ $report->title }}</h1>
            </td>
            <td style="text-align:right; vertical-align:top;" class="stencil">
                {{ __('Consultation') }}<br>
                {{ $consultation?->reference }}
            </td>
        </tr>
    </table>

    <hr class="stitch">

    <table class="facts">
        <tr>
            <td class="key">{{ __('Prepared for') }}</td>
            <td>{{ $consultation?->full_name }}</td>
        </tr>
        @if ($consultation?->animal_type || $consultation?->flock_size)
            <tr>
                <td class="key">{{ __('The farm') }}</td>
                <td>
                    {{ collect([
                        $consultation->farm_type,
                        $consultation->animal_type,
                        $consultation->flock_size ? trans_choice('one bird|:count birds', $consultation->flock_size, ['count' => number_format($consultation->flock_size)]) : null,
                        collect([$consultation->lga, $consultation->state])->filter()->implode(', ') ?: null,
                    ])->filter()->implode(' · ') }}
                </td>
            </tr>
        @endif
        <tr>
            <td class="key">{{ __('Date') }}</td>
            <td>{{ ($report->published_at ?? $report->created_at ?? now())->format('j F Y') }}</td>
        </tr>
    </table>

    @if ($consultation?->situation)
        <h2>{{ __('What you told us') }}</h2>
        <p class="body">{{ $consultation->situation }}</p>
    @endif

    <h2>{{ __('What we found') }}</h2>
    <p class="body">{{ $report->findings }}</p>

    <h2>{{ __('What we recommend') }}</h2>
    <p class="body">{{ $report->recommendations }}</p>

    @if ($report->follow_up_actions)
        <h2>{{ __('What to do next') }}</h2>
        <p class="body">{{ $report->follow_up_actions }}</p>
    @endif

    <hr class="rule">

    <p style="font-size:9pt;">
        {{ __('Prepared by :company.', ['company' => $issuer['name']]) }}
        @if ($issuer['rc_number'])
            {{ __('RC :number.', ['number' => $issuer['rc_number']]) }}
        @endif
        @if ($issuer['phone'] || $issuer['email'])
            {{ __('Questions: :contact', ['contact' => collect([$issuer['phone'], $issuer['email']])->filter()->implode(' · ')]) }}
        @endif
    </p>

    <div class="foot">
        {{ __('This advice is based on what was described and observed at the time. Conditions on a farm change.') }}
    </div>
</body>
</html>
