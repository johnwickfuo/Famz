@extends('emails.layout')

@section('subject', __('Your consultation price'))
@section('preheader', __('Pay to get started.'))

@section('content')
    <x-mail.heading>{{ __('Your consultation price') }}</x-mail.heading>

    <x-mail.body>
        {{ __('Hello :name. Following our conversation, here is what this will cost.', ['name' => $consultation->full_name]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $consultation->reference,
        __('Service') => $consultation->tier->label(),
        __('Price') => $consultation->quotedAmount(),
    ])" />

    @if ($consultation->quote_note)
        <x-mail.body>{{ $consultation->quote_note }}</x-mail.body>
    @endif

    <x-mail.button :url="route('consultations.show', $consultation->reference)">
        {{ __('Pay :amount', ['amount' => $consultation->quotedAmount()]) }}
    </x-mail.button>

    <x-mail.body>
        {{ __('If the price is not what you expected, reply to this email or call us before paying.') }}
    </x-mail.body>
@endsection
