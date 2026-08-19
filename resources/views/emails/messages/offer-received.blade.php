@extends('emails.layout')

@section('subject', branded(__('You have an offer on {company}')))
@section('preheader', __(':name has made an offer.', ['name' => $offer->initiator?->displayName() ?? __('Somebody')]))

@section('content')
    <x-mail.heading>{{ __('You have an offer') }}</x-mail.heading>

    <x-mail.body>
        {{ __(':name has made an offer on :thing.', [
            'name' => $offer->initiator?->displayName() ?? __('Somebody'),
            'thing' => $offer->offerable?->title ?? $offer->offerable?->name ?? __('one of your listings'),
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

    <x-mail.button :url="$actionUrl">{{ __('Answer this offer') }}</x-mail.button>

    <x-mail.body>
        {{ __('You can accept it, turn it down, or come back with a price of your own.') }}
    </x-mail.body>
@endsection
