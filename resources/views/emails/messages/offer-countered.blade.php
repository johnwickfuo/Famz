@extends('emails.layout')

@section('subject', __('Your offer has been countered'))
@section('preheader', __('A new price is on the table.'))

@section('content')
    <x-mail.heading>{{ __('A new price is on the table') }}</x-mail.heading>

    <x-mail.body>
        {{ __(':name has come back with a different price.', [
            'name' => $offer->initiator?->displayName() ?? __('The other side'),
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Quantity') => (string) $offer->quantity,
        __('Price each') => $offer->unitPrice(),
        __('Total') => $offer->totalPrice(),
        __('Answer by') => $offer->expires_at?->format('j F Y, H:i'),
    ])" />

    @if ($offer->message)
        <x-mail.body>“{{ $offer->message }}”</x-mail.body>
    @endif

    <x-mail.button :url="$actionUrl">{{ __('See the counter-offer') }}</x-mail.button>
@endsection
