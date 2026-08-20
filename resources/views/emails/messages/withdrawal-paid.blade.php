@extends('emails.layout')

@section('subject', __('Paid: :amount sent to your bank account', ['amount' => \App\Support\Money::fromKobo($withdrawal->amount_kobo)]))
@section('preheader', __('Reference :reference.', ['reference' => $withdrawal->reference]))

@section('content')
    <x-mail.heading>{{ __('Your money is on its way') }}</x-mail.heading>

    <x-mail.body>
        {{ __('We have sent :amount to your bank account. Depending on your bank it can take a few hours to appear.', [
            'amount' => \App\Support\Money::fromKobo($withdrawal->amount_kobo),
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Amount') => \App\Support\Money::fromKobo($withdrawal->amount_kobo),
        __('Reference') => $withdrawal->reference,
        __('Bank') => $withdrawal->payoutAccount?->bank_name,
        __('Account') => $withdrawal->payoutAccount?->maskedNumber(),
        __('Sent') => $withdrawal->processed_at?->format('j F Y, H:i'),
    ])" />

    <x-mail.button :url="$actionUrl">{{ __('See your earnings') }}</x-mail.button>

    {{--
        The security line. An unexpected payout notice is the first sign a
        seller gets that somebody has changed their payout account, so the
        email has to tell them what to do about it rather than only reporting
        the good news.
    --}}
    <x-mail.body>
        {{ __('If you were not expecting this, contact us straight away — it may mean somebody has changed your payout details.') }}
    </x-mail.body>
@endsection
