@extends('emails.layout')

@section('subject', __('On its way — :reference', ['reference' => $subOrder->reference]))
@section('preheader', __('Your order has left the seller.'))

@section('content')
    <x-mail.heading>{{ __('It is on its way') }}</x-mail.heading>

    <x-mail.body>
        {{ __(':seller has sent your order.', ['seller' => $subOrder->seller?->business_name ?? __('The seller')]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Order') => $subOrder->reference,
        __('Delivery') => $subOrder->delivery_method?->label(),
        __('Sent') => $subOrder->shipped_at?->format('j F Y, H:i'),
    ])" />

    <x-mail.button :url="$actionUrl">{{ __('Mark it delivered when it arrives') }}</x-mail.button>

    <x-mail.body>
        {{ __('Marking it delivered is what releases the money to the seller. If it does not arrive, or arrives wrong, raise it on that page instead — the money stays held while a dispute is open.') }}
    </x-mail.body>
@endsection
