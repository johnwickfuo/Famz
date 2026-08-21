@extends('emails.layout')

@section('subject', __('New order to pack — :reference', ['reference' => $subOrder->reference]))
@section('preheader', __('Somebody has paid for an order from you.'))

@section('content')
    <x-mail.heading>{{ __('You have an order') }}</x-mail.heading>

    <x-mail.body>
        {{ __('It is paid for. The money is held and comes to you once the buyer has it.') }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Order') => $subOrder->reference,
        __('Buyer') => $subOrder->order?->user?->displayName(),
        __('Total') => \App\Support\Money::fromKobo($subOrder->grandTotalKobo()),
        __('Delivery') => $subOrder->delivery_method?->label(),
        __('Deliver to') => $subOrder->order?->delivery_state,
    ])" />

    @foreach ($subOrder->items as $item)
        <x-mail.body>
            {{ $item->quantity }} &times; {{ $item->product_name }}
        </x-mail.body>
    @endforeach

    <x-mail.button :url="$actionUrl">{{ __('Accept and pack it') }}</x-mail.button>

    <x-mail.body>
        {{-- The perishable and live-animal rules make this more than a nicety. --}}
        {{ __('Accept it as soon as you can so the buyer knows it is being dealt with. If you cannot fulfil it, say so now rather than later.') }}
    </x-mail.body>
@endsection
