@extends('emails.layout')

@section('subject', __('Your request closes soon'))
@section('preheader', trans_choice('It closes tomorrow.|It closes in :count days.', $daysLeft, ['count' => $daysLeft]))

@section('content')
    <x-mail.heading>
        {{ trans_choice('Your request closes tomorrow|Your request closes in :count days', $daysLeft, ['count' => $daysLeft]) }}
    </x-mail.heading>

    <x-mail.body>“{{ $buyerRequest->title }}”</x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Offers so far') => (string) $buyerRequest->offers()->count(),
        __('Closes') => $buyerRequest->expires_at?->format('j F Y, H:i'),
    ])" />

    <x-mail.body>
        {{ __('If one of them suits you, take it before the request closes. If none do, you can post it again.') }}
    </x-mail.body>

    <x-mail.button :url="route('requests.manage', $buyerRequest->slug)">
        {{ __('Compare the offers') }}
    </x-mail.button>
@endsection
