@extends('emails.layout')

@section('subject', __('We have your request'))
@section('preheader', __('Keep this reference: :reference', ['reference' => $consultation->reference]))

@section('content')
    <x-mail.heading>{{ __('We have your request') }}</x-mail.heading>

    <x-mail.body>
        {{-- The one thing they need if they lose this email: their reference,
             which is also how a guest opens their consultation again. --}}
        {{ __('Hello :name. Somebody will be in touch — here is what we have.', ['name' => $consultation->full_name]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $consultation->reference,
        __('Service') => $consultation->tier->label(),
        __('We will respond') => $consultation->response_due_at?->format('j F Y, H:i'),
        __('Your number') => $consultation->phone,
    ])" />

    <x-mail.button :url="route('consultations.show', $consultation->reference)">
        {{ __('Open my consultation') }}
    </x-mail.button>

    <x-mail.body>
        {{ __('Nothing has been charged. We agree a price with you after we have talked, and only then do you pay.') }}
    </x-mail.body>
@endsection
