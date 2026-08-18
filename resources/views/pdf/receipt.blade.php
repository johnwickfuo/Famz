@extends('pdf.layout')

@section('title', __('Receipt :reference', ['reference' => $reference]))

@section('content')
    <h1>{{ __('Receipt') }}</h1>

    <table class="details">
        <tr><th>{{ __('Reference') }}</th><td>{{ $reference }}</td></tr>
        <tr><th>{{ __('Issued to') }}</th><td>{{ $buyerName }}</td></tr>
        <tr><th>{{ __('Date') }}</th><td>{{ $issuedAt->format('j F Y') }}</td></tr>
    </table>

    <h2>{{ __('Items') }}</h2>

    <table class="details">
        @foreach ($lines as $line)
            <tr>
                <th>{{ $line['description'] }}</th>
                <td>
                    {{ $line['quantity'] }} &times; &#8358;{{ number_format($line['unit_price'], 2) }}
                    &nbsp;=&nbsp; <strong>&#8358;{{ number_format($line['total'], 2) }}</strong>
                </td>
            </tr>
        @endforeach
        <tr>
            <th>{{ __('Total') }}</th>
            <td><strong>&#8358;{{ number_format($total, 2) }}</strong></td>
        </tr>
    </table>

    <p style="font-size:9pt;">
        {{ branded(__('Thank you for buying through {company}.')) }}
    </p>
@endsection
