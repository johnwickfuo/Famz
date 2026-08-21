@extends('emails.layout')

@section('subject', __('Payment received — order :reference', ['reference' => $order->reference]))
@section('preheader', __('Your payment went through. Here is what happens next.'))

@section('content')
    <x-mail.heading>{{ __('We have your payment') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Thank you. Your money is held by {company} until what you ordered arrives — the seller is not paid before then.')) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Order') => $order->reference,
        __('Paid') => \App\Support\Money::fromKobo((int) $order->total_kobo),
        __('Date') => $order->paid_at?->format('j F Y, H:i'),
    ])" />

    @foreach ($order->subOrders as $part)
        <x-mail.body>
            <strong>{{ $part->seller?->business_name ?? __('Seller') }}</strong> —
            {{ trans_choice('{1} one item|[2,*] :count items', $part->items->count(), ['count' => $part->items->count()]) }},
            {{ \App\Support\Money::fromKobo($part->grandTotalKobo()) }}
        </x-mail.body>
    @endforeach

    <x-mail.button :url="$actionUrl">{{ __('See your order') }}</x-mail.button>

    <x-mail.body>
        {{-- Said here because it is the thing buyers do not know and most need to. --}}
        {{ __('When it arrives, mark it delivered on that page. That is what releases the money to the seller — and if something is wrong, that is where you raise it instead.') }}
    </x-mail.body>
@endsection
