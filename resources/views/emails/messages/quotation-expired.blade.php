@extends('emails.layout')

@section('subject', __('Your proposal has lapsed'))
@section('preheader', __('We can re-cost it whenever you are ready.'))

@section('content')
    <x-mail.heading>{{ __('Your proposal has lapsed') }}</x-mail.heading>

    <x-mail.body>
        {{ __('Hello :name. The proposal we sent you on :date has passed its validity date.', [
            'name' => $request?->user?->name,
            'date' => $quotation->sent_at?->format('j F Y'),
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $request?->reference,
        __('Proposal') => $quotation->title,
        __('Was valid until') => $quotation->valid_until?->format('j F Y'),
        __('Quoted total') => $quotation->total(),
    ])" />

    <x-mail.body>
        {{ __('This is not us withdrawing the offer — it is us being honest. Cement, roofing sheet, equipment and stock all move quickly here, and we would rather re-check the numbers than start a job on a price that is out of date.') }}
    </x-mail.body>

    <x-mail.body>
        {{ __('You can still read the proposal on your dashboard. If you are still interested, call or email us and we will re-cost it — that is usually quick, since the work of designing it is already done.') }}
    </x-mail.body>

    <x-mail.button :url="route('quotations.show', $request?->reference)">
        {{ __('See the proposal') }}
    </x-mail.button>
@endsection
