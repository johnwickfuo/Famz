@extends('emails.layout')

@section('subject', $forBuyer ? __('Your offer was accepted') : __('You accepted an offer'))
@section('preheader', $forBuyer ? __('Pay to complete it.') : __('The buyer has been sent a link to pay.'))

@section('content')
    <x-mail.heading>
        {{ $forBuyer ? __('Your offer was accepted') : __('You accepted an offer') }}
    </x-mail.heading>

    <x-mail.detail-table :rows="array_filter([
        __('Quantity') => (string) $offer->quantity,
        __('Price each') => $offer->unitPrice(),
        __('Total') => $offer->totalPrice(),
    ])" />

    @if ($forBuyer && $purchase)
        <x-mail.body>
            {{-- Said plainly: the price is only theirs while the window is open. --}}
            {{ __('This price is held for you until :when. After that the listing goes back to its usual price and the stock goes back on sale.', [
                'when' => $purchase->expires_at->format('j F Y, H:i'),
            ]) }}
        </x-mail.body>

        <x-mail.button :url="route('negotiated.show', $purchase->token)">
            {{ __('Complete this purchase') }}
        </x-mail.button>

        <x-mail.body>
            {{ __('This link is yours alone. Anybody who has it can buy at your price, so do not pass it on.') }}
        </x-mail.body>
    @else
        <x-mail.body>
            {{ __('The buyer has been sent a private link to pay at the agreed price. You will get the order as soon as they do.') }}
        </x-mail.body>

        <x-mail.button :url="url('/seller/sub-orders')">{{ __('Open my orders') }}</x-mail.button>
    @endif
@endsection
