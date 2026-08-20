{{--
    A farm setup proposal, as a document the client takes to a bank.

    Everything about the issuer comes from $issuer, never from a literal. The
    name on it was frozen when the proposal was sent, so reissuing an old
    version reproduces the document the client actually received; the contact
    details are read live, because a stale phone number helps nobody.

    Colours are the Phase 1 tokens by value rather than by variable — DomPDF has
    no custom properties, so the design system reaches paper as constants.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->title }}</title>
    <style>
        @page { margin: 14mm 14mm 22mm; }

        body { font-family: 'DejaVu Sans', sans-serif; color: #14170F; font-size: 9.5pt; line-height: 1.5; }

        .stencil { font-size: 7.5pt; letter-spacing: 2px; text-transform: uppercase; color: #575A52; }

        h1 { font-size: 16pt; margin: 2mm 0 1mm; line-height: 1.2; }
        h2 { font-size: 11pt; margin: 7mm 0 2mm; color: #0E5138; }
        h3 { font-size: 9.5pt; margin: 4mm 0 1.5mm; }

        /* The seam, carried onto paper. */
        .stitch { border: 0; border-top: 3px dashed #F5B711; margin: 3mm 0 5mm; }
        .rule { border: 0; border-top: 2px solid #CED1CA; margin: 5mm 0 3mm; }

        .body { white-space: pre-line; }
        .muted { color: #575A52; }

        table.facts { width: 100%; border-collapse: collapse; margin: 3mm 0 0; }
        table.facts td { padding: 1.8mm 2.5mm; border: 1px solid #CED1CA; vertical-align: top; }
        table.facts td.key { width: 32mm; color: #575A52; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 1px; }

        table.lines { width: 100%; border-collapse: collapse; margin: 2mm 0 0; }
        table.lines th {
            text-align: left; font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase;
            color: #575A52; border-bottom: 2px solid #14170F; padding: 1.5mm 2mm;
        }
        table.lines td { padding: 1.8mm 2mm; border-bottom: 1px solid #DFE2DB; vertical-align: top; }
        table.lines td.num, table.lines th.num { text-align: right; white-space: nowrap; }
        table.lines tr.section-total td {
            border-bottom: 0; border-top: 1px solid #CED1CA;
            font-weight: bold; font-size: 9pt;
        }

        table.totals { width: 72mm; border-collapse: collapse; margin-left: auto; margin-top: 4mm; }
        table.totals td { padding: 1.8mm 2.5mm; }
        table.totals td.num { text-align: right; white-space: nowrap; }
        table.totals tr.grand td {
            border-top: 3px double #14170F; font-size: 12pt; font-weight: bold; padding-top: 2.5mm;
        }

        .validity {
            border: 2px solid #14170F; padding: 3mm; margin-top: 6mm; background: #FDF6E3;
        }

        .contact { border: 2px solid #0E5138; padding: 4mm; margin-top: 6mm; }

        .foot { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7.5pt; color: #575A52; }

        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Letterhead                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="vertical-align:top;">
                @if ($issuer['has_logo'] && $issuer['logo_url'])
                    <img src="{{ $issuer['logo_url'] }}" alt="{{ $issuer['name'] }}" height="30">
                @else
                    <p class="stencil" style="margin:0;">{{ $issuer['name'] }}</p>
                @endif

                <p class="muted" style="margin:2mm 0 0; font-size:8pt;">
                    @if ($issuer['address']){{ $issuer['address'] }}<br>@endif
                    {{ collect([$issuer['phone'], $issuer['email']])->filter()->implode(' · ') }}
                    @if ($issuer['rc_number'])<br>{{ __('RC :number', ['number' => $issuer['rc_number']]) }}@endif
                </p>
            </td>
            <td style="text-align:right; vertical-align:top;">
                <p class="stencil" style="margin:0;">{{ __('Proposal') }}</p>
                <p class="figures" style="margin:1mm 0 0; font-size:11pt; font-weight:bold;">
                    {{ $request?->reference }}
                </p>
                <p class="muted" style="margin:1mm 0 0; font-size:8pt;">
                    {{ __('Version :number', ['number' => $quotation->version]) }}<br>
                    {{ ($quotation->sent_at ?? $quotation->created_at ?? now())->format('j F Y') }}
                </p>
            </td>
        </tr>
    </table>

    <hr class="stitch">

    <h1>{{ $quotation->title }}</h1>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Who it is for, and what they asked for                              --}}
    {{-- ------------------------------------------------------------------ --}}
    <table class="facts">
        <tr>
            <td class="key">{{ __('Prepared for') }}</td>
            <td>
                {{ $request?->user?->name }}
                @if ($request?->user?->email)<br><span class="muted">{{ $request->user->email }}</span>@endif
            </td>
        </tr>
        <tr>
            <td class="key">{{ __('Project') }}</td>
            <td>
                {{ collect([
                    $request?->project_type?->label(),
                    $request?->farm_type,
                    $request?->capacity(),
                ])->filter()->implode(' · ') }}
            </td>
        </tr>
        @if ($request?->place() || $request?->landSize())
            <tr>
                <td class="key">{{ __('Site') }}</td>
                <td>
                    {{ collect([
                        $request?->place(),
                        $request?->landSize(),
                        $request?->owns_land ? __('Client owns the land') : __('Land not yet secured'),
                    ])->filter()->implode(' · ') }}
                </td>
            </tr>
        @endif
        @if ($request && filled($request->scopeLabels()))
            <tr>
                <td class="key">{{ __('Scope requested') }}</td>
                <td>{{ implode(' · ', $request->scopeLabels()) }}</td>
            </tr>
        @endif
    </table>

    {{-- ------------------------------------------------------------------ --}}
    {{-- The prose sections                                                  --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($quotation->executive_summary)
        <h2>{{ __('Summary') }}</h2>
        <p class="body">{{ $quotation->executive_summary }}</p>
    @endif

    @if ($quotation->scope_of_work)
        <h2>{{ __('Scope of work') }}</h2>
        <p class="body">{{ $quotation->scope_of_work }}</p>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- The money                                                           --}}
    {{-- ------------------------------------------------------------------ --}}
    <h2>{{ __('Costs') }}</h2>

    @foreach ($sections as $section => $items)
        <div class="avoid-break">
            <h3>{{ $section }}</h3>

            <table class="lines">
                <thead>
                    <tr>
                        <th>{{ __('Item') }}</th>
                        <th class="num">{{ __('Qty') }}</th>
                        <th class="num">{{ __('Unit price') }}</th>
                        <th class="num">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td class="num">
                                {{-- Non-breaking, so "10,000 birds" never splits across the column. --}}
                                {{ $item->unit ? $item->quantityLabel()."\u{00A0}".$item->unit : $item->quantityLabel() }}
                            </td>
                            <td class="num">{{ $item->unitPrice() }}</td>
                            <td class="num">{{ $item->total() }}</td>
                        </tr>
                    @endforeach

                    {{-- Only worth a line when there is more than one item to add up. --}}
                    @if ($items->count() > 1)
                        <tr class="section-total">
                            <td colspan="3">{{ __(':section total', ['section' => $section]) }}</td>
                            <td class="num">{{ \App\Support\Money::fromKobo($sectionTotals[$section] ?? 0) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endforeach

    <table class="totals">
        <tr>
            <td>{{ __('Subtotal') }}</td>
            <td class="num">{{ $quotation->subtotal() }}</td>
        </tr>
        @if ($quotation->contingency_kobo > 0)
            <tr>
                <td>
                    {{ __('Contingency') }}
                    <span class="muted">({{ rtrim(rtrim(number_format((float) $quotation->contingency_percent, 2), '0'), '.') }}%)</span>
                </td>
                <td class="num">{{ $quotation->contingency() }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>{{ __('Total') }}</td>
            <td class="num">{{ $quotation->total() }}</td>
        </tr>
    </table>

    {{-- ------------------------------------------------------------------ --}}
    {{-- The small print that stops arguments                                --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($quotation->assumptions)
        <h2>{{ __('What we have assumed') }}</h2>
        <p class="body">{{ $quotation->assumptions }}</p>
    @endif

    @if ($quotation->exclusions)
        <h2>{{ __('What is not included') }}</h2>
        <p class="body">{{ $quotation->exclusions }}</p>
    @endif

    @if ($quotation->timeline_description)
        <h2>{{ __('How long it takes') }}</h2>
        <p class="body">{{ $quotation->timeline_description }}</p>
    @endif

    @if ($quotation->payment_terms)
        <h2>{{ __('Payment terms') }}</h2>
        <p class="body">{{ $quotation->payment_terms }}</p>
    @endif

    @if ($quotation->valid_until)
        <div class="validity avoid-break">
            <p class="stencil" style="margin:0;">{{ __('How long this price stands') }}</p>
            <p style="margin:1.5mm 0 0;">
                <strong>{{ __('Valid until :date.', ['date' => $quotation->valid_until->format('j F Y')]) }}</strong>
                {{ __('Prices for building materials, equipment and stock move quickly. After this date we will need to re-cost the work before starting.') }}
            </p>
        </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- How to say yes. Deliberately a phone number, not a button.          --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="contact avoid-break">
        <p class="stencil" style="margin:0;">{{ __('To go ahead') }}</p>
        <p style="margin:1.5mm 0 0;">
            {{ __('Call or email us and we will take it from there. Quote :reference when you do.', [
                'reference' => $request?->reference,
            ]) }}
        </p>
        <p style="margin:2mm 0 0;">
            <strong>{{ $issuer['name'] }}</strong><br>
            @if ($issuer['phone']){{ $issuer['phone'] }}@endif
            @if ($issuer['whatsapp'] && $issuer['whatsapp'] !== $issuer['phone'])
                · {{ __('WhatsApp :number', ['number' => $issuer['whatsapp']]) }}
            @endif
            @if ($issuer['email'])<br>{{ $issuer['email'] }}@endif
            @if ($issuer['address'])<br><span class="muted">{{ $issuer['address'] }}</span>@endif
        </p>
    </div>

    <hr class="rule">

    <p style="font-size:8pt;" class="muted">
        {{ __('Prepared by :company.', ['company' => $issuer['name']]) }}
        @if ($quotation->preparer?->name)
            {{ __('Written by :person.', ['person' => $quotation->preparer->name]) }}
        @endif
        @if ($issuer['rc_number'])
            {{ __('RC :number.', ['number' => $issuer['rc_number']]) }}
        @endif
    </p>

    <div class="foot">
        {{ $request?->reference }} · {{ __('Version :number', ['number' => $quotation->version]) }} ·
        {{ __('This is an estimate based on the information supplied and is not a contract.') }}
    </div>
</body>
</html>
