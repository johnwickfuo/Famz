@extends('emails.layout')

@section('subject', __('Your offer was not taken up'))
@section('preheader', __('Nothing was agreed this time.'))

@section('content')
    <x-mail.heading>{{ __('Your offer was not taken up') }}</x-mail.heading>

    <x-mail.body>
        {{ $reason ?: __('Nothing was agreed this time. It happens — plenty of other sellers are on the market.') }}
    </x-mail.body>

    <x-mail.detail-table :rows="[
        __('You offered') => $offer->unitPrice().' '.__('each'),
        __('Quantity') => (string) $offer->quantity,
    ]" />

    <x-mail.button :url="$actionUrl">{{ __('Keep looking') }}</x-mail.button>
@endsection
