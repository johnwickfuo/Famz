@extends('emails.layout')

@section('subject', $approved ? branded(__('Your request is live on {company}')) : __('We could not publish your request'))
@section('preheader', $approved ? __('Sellers can see it now.') : __('Have a look at why.'))

@section('content')
    <x-mail.heading>
        {{ $approved ? __('Your request is live') : __('We could not publish your request') }}
    </x-mail.heading>

    <x-mail.body>“{{ $buyerRequest->title }}”</x-mail.body>

    @if ($approved)
        <x-mail.detail-table :rows="array_filter([
            __('Wanted') => $buyerRequest->quantity.' '.$buyerRequest->unit,
            __('Delivered to') => $buyerRequest->location(),
            __('Budget') => $buyerRequest->budgetLabel(),
            __('Open until') => $buyerRequest->expires_at?->format('j F Y'),
        ])" />

        <x-mail.body>
            {{ __('Sellers in this category can see it now. Offers come to you here, and you choose which one to take.') }}
        </x-mail.body>

        <x-mail.button :url="route('requests.manage', $buyerRequest->slug)">
            {{ __('See my request') }}
        </x-mail.button>
    @else
        <x-mail.body>
            {{ $buyerRequest->rejection_reason ?: __('It did not meet the rules of the board.') }}
        </x-mail.body>

        <x-mail.body>
            {{ __('You are welcome to post it again with that put right.') }}
        </x-mail.body>

        <x-mail.button :url="route('requests.create')">{{ __('Post another request') }}</x-mail.button>
    @endif
@endsection
